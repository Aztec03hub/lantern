<?php

/*
 * PairMergeService.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Services\Internal\Pair;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Events\Model\TransactionGroup\DestroyedSingleTransactionGroup;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventObjects;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending;
use FireflyIII\Exceptions\PairRefusedException;
use FireflyIII\Models\PairMerge;
use FireflyIII\Models\PlaidTransactionLink;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Services\Internal\Destroy\JournalDestroyService;
use FireflyIII\Services\Internal\Support\PlaidLinkService;
use Illuminate\Support\Facades\DB;

/**
 * Merges two single-link journals (the two sides of one own-account transfer) into ONE journal with TWO Plaid links,
 * and undoes that exactly. Design: docs/design/transfer-pairer.md sections 5 and 6, contract: docs/core-pair-merge.md.
 *
 * Everything runs inside ONE database transaction. Every precondition is re-read from the database after the locks are
 * held. Nothing here calls Model::update()/save() on a PlaidTransactionLink (composite key: use the keyed query builder).
 */
class PairMergeService
{
    /** @var array<int, string> account types that count as "one of my own accounts" */
    private const array OWN_TYPES = [AccountTypeEnum::ASSET->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::LOAN->value, AccountTypeEnum::MORTGAGE->value];

    /** Tables whose rows of a journal are snapshotted: table => [column holding the journal id, extra where]. */
    private const array JOURNAL_TABLES = [
        'transactions'                 => ['transaction_journal_id', null],
        'journal_meta'                 => ['transaction_journal_id', null],
        'notes'                        => ['noteable_id', 'noteable_type'],
        'locations'                    => ['locatable_id', 'locatable_type'],
        'audit_log_entries'            => ['auditable_id', 'auditable_type'],
        'tag_transaction_journal'      => ['transaction_journal_id', null],
        'category_transaction_journal' => ['transaction_journal_id', null],
        'budget_transaction_journal'   => ['transaction_journal_id', null],
    ];

    /**
     * A monotonic version stamp. updated_at has one second resolution, so "now" can equal the stamp a reader saw
     * before an edit. The stamp therefore always moves forward by at least one second.
     */
    public static function nextVersion(null|CarbonInterface|string $previous): Carbon
    {
        $now = Carbon::now();
        if (null === $previous) {
            return $now;
        }
        $floor = Carbon::parse($previous)->addSecond();

        return $floor->greaterThan($now) ? $floor : $now;
    }

    /**
     * The stamps of a journal and of its group.
     *
     * @return null|array{journal: ?Carbon, group: ?Carbon}
     */
    public static function stampsOf(int $journalId): ?array
    {
        $row = DB::table('transaction_journals')
            ->join('transaction_groups', 'transaction_groups.id', '=', 'transaction_journals.transaction_group_id')
            ->where('transaction_journals.id', $journalId)
            ->first(['transaction_journals.updated_at as journal_at', 'transaction_groups.updated_at as group_at'])
        ;
        if (null === $row) {
            return null;
        }

        return ['journal' => null === $row->journal_at ? null : Carbon::parse($row->journal_at), 'group' => null === $row->group_at ? null : Carbon::parse($row->group_at)];
    }

    /** The version of a journal: the later of its own and its group's updated_at (null when the journal is unknown). */
    public static function versionOf(int $journalId): ?Carbon
    {
        $stamps = self::stampsOf($journalId);
        if (null === $stamps || null === $stamps['journal'] || null === $stamps['group']) {
            return $stamps['journal'] ?? $stamps['group'] ?? null;
        }

        return $stamps['group']->greaterThan($stamps['journal']) ? $stamps['group'] : $stamps['journal'];
    }

    /** The version of a group: the latest updated_at of the group and of its journals. */
    public static function groupVersion(int $groupId): ?Carbon
    {
        $stamps = DB::table('transaction_journals')->where('transaction_group_id', $groupId)->pluck('updated_at')->all();
        $stamps[] = DB::table('transaction_groups')->where('id', $groupId)->value('updated_at');
        $latest = null;
        foreach ($stamps as $stamp) {
            $at = null === $stamp ? null : Carbon::parse($stamp);
            if (null !== $at && (null === $latest || $at->greaterThan($latest))) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /** Move a group and all its journals to one new stamp, at least one second past $previous. */
    public static function bumpGroupVersion(int $groupId, null|CarbonInterface|string $previous): Carbon
    {
        $next  = self::nextVersion($previous);
        $stamp = $next->toDateTimeString();
        DB::table('transaction_journals')->where('transaction_group_id', $groupId)->update(['updated_at' => $stamp]);
        DB::table('transaction_groups')->where('id', $groupId)->update(['updated_at' => $stamp]);

        return $next;
    }

    /**
     * Bump the version of a journal AND its group to the same stamp, with the keyed query builder. The API shows the
     * group's stamp, so after every bump the stamp a client reads is the one the stale check compares.
     */
    public static function bumpJournalVersion(int $journalId, null|CarbonInterface|string $previous): Carbon
    {
        $next    = self::nextVersion($previous);
        $stamp   = $next->toDateTimeString();
        $groupId = DB::table('transaction_journals')->where('id', $journalId)->value('transaction_group_id');
        DB::table('transaction_journals')->where('id', $journalId)->update(['updated_at' => $stamp]);
        if (null !== $groupId) {
            DB::table('transaction_groups')->where('id', $groupId)->update(['updated_at' => $stamp]);
        }

        return $next;
    }

    /**
     * @param null|array<string, mixed> $evidence
     *
     * @return array{merge: PairMerge, created: bool}
     *
     * @throws PairRefusedException
     */
    public function merge(int $userGroupId, int $keepGroupId, int $absorbGroupId, string $keepUpdatedAt, string $absorbUpdatedAt, ?array $evidence): array
    {
        if ($keepGroupId === $absorbGroupId) {
            throw new PairRefusedException(422, 'same_group');
        }
        $destroyObjects = null;
        $updateObjects  = null;
        $result         = DB::transaction(function () use ($userGroupId, $keepGroupId, $absorbGroupId, $keepUpdatedAt, $absorbUpdatedAt, $evidence, &$destroyObjects, &$updateObjects): array {
            $journalIds = $this->journalIdsOf($userGroupId, [$keepGroupId, $absorbGroupId]);
            $plaidIds   = $this->plaidIdsOfJournals($userGroupId, $journalIds);
            $this->lock($userGroupId, $journalIds, $plaidIds);

            // from here on every read is the committed truth: no other writer can change these rows until we finish.
            $keepGroup  = $this->liveGroup($userGroupId, $keepGroupId);
            $live       = PairMerge::where('user_group_id', $userGroupId)->where('keep_group_id', $keepGroupId)->where('absorbed_group_id', $absorbGroupId)->whereNull('unmerged_at')->first();
            if (null !== $live) {
                return ['merge' => $live, 'created' => false];
            }
            if (PairMerge::where('user_group_id', $userGroupId)->where('absorbed_group_id', $absorbGroupId)->whereNull('unmerged_at')->exists()) {
                throw new PairRefusedException(409, 'not_single');
            }
            $absorbGroup = $this->liveGroup($userGroupId, $absorbGroupId);
            $keepJ       = $this->singleJournalId($keepGroup);
            $absJ        = $this->singleJournalId($absorbGroup);
            $this->verifyLocked($userGroupId, [$keepJ, $absJ], $plaidIds);

            $keepLink    = $this->singleLink($userGroupId, $keepJ);
            $absLink     = $this->singleLink($userGroupId, $absJ);
            $this->refuseIfStateWouldBeLost($keepJ);
            $this->refuseIfStateWouldBeLost($absJ);
            $this->refuseIfStale($keepJ, $keepUpdatedAt, 'keep');
            $this->refuseIfStale($absJ, $absorbUpdatedAt, 'absorb');
            $typeName    = $this->validateDomain($keepJ, $absJ);

            // the DELETED event describes the absorbed group as it was; the UPDATED event also needs keep's old accounts.
            $destroyObjects = TransactionGroupEventObjects::collectFromTransactionGroup($absorbGroup);
            $updateObjects  = TransactionGroupEventObjects::collectFromTransactionGroup($keepGroup);

            $keepBefore  = $this->keepState($userGroupId, $keepJ);
            $absorbedRow = (array) DB::table('transaction_journals')->where('id', $absJ)->first();
            $snapshot    = $this->snapshot($userGroupId, $absorbGroup, $absJ);

            $keepRows    = $this->rowsOf($keepJ);
            $absRows     = $this->rowsOf($absJ);
            $this->applyToKeep($userGroupId, $keepJ, $absJ, $typeName, $keepLink, $absLink, $absorbedRow);
            $keepDate    = (string) DB::table('transaction_journals')->where('id', $keepJ)->value('date');
            $this->flagLaterRows(array_merge(array_column($keepRows['rows'], 'account_id'), array_column($absRows['rows'], 'account_id')), min($keepDate, (string) $absorbedRow['date']));
            $version     = self::bumpJournalVersion($keepJ, self::versionOf($keepJ));
            $keepAfter   = $this->keepState($userGroupId, $keepJ);

            $journal     = TransactionJournal::findOrFail($absJ);
            app(JournalDestroyService::class)->destroy($journal);
            $absorbGroup->delete();

            $merge       = PairMerge::create([
                'user_group_id'         => $userGroupId,
                'keep_group_id'         => $keepGroupId,
                'keep_journal_id'       => $keepJ,
                'absorbed_group_id'     => $absorbGroupId,
                'absorbed_journal_id'   => $absJ,
                'keep_before'           => $keepBefore,
                'keep_after'            => $keepAfter,
                'absorbed_snapshot'     => $snapshot,
                'keep_after_updated_at' => $version,
                'evidence'              => $evidence,
                'merged_at'             => Carbon::now(),
            ]);

            return ['merge' => $merge, 'created' => true];
        });
        if ($result['created'] && null !== $destroyObjects && null !== $updateObjects) {
            $this->afterCommit(function () use ($updateObjects, $destroyObjects, $keepGroupId): void {
                $updateObjects->appendFromTransactionGroup(TransactionGroup::findOrFail($keepGroupId));
                $this->fireUpdated($updateObjects);
                event(new DestroyedSingleTransactionGroup(new TransactionGroupEventFlags(), $destroyObjects));
                event(new WebhookMessagesRequestSending());
            });
        }

        return $result;
    }

    /**
     * Undo a merge. Returns the merge row (already unmerged when the call was a repeat).
     *
     * @return array{merge: PairMerge, changed: bool, overridden: array<int, array{field: string, expected: mixed, current: mixed}>}
     *
     * @throws PairRefusedException
     */
    public function unmerge(int $userGroupId, int $pairMergeId, bool $force): array
    {
        $updateObjects = null;
        $result        = DB::transaction(function () use ($userGroupId, $pairMergeId, $force, &$updateObjects): array {
            $pre = PairMerge::where('user_group_id', $userGroupId)->where('id', $pairMergeId)->first();
            if (null === $pre) {
                throw new PairRefusedException(404, 'not_found');
            }
            $journalIds = [$pre->keep_journal_id, $pre->absorbed_journal_id];
            $plaidIds   = array_merge(
                $this->plaidIdsOfJournals($userGroupId, $journalIds),
                array_column($pre->absorbed_snapshot['plaid_links'] ?? [], 'plaid_transaction_id'),
                array_column($pre->keep_before['plaid_links'] ?? [], 'plaid_transaction_id'),
            );
            $this->lock($userGroupId, $journalIds, $plaidIds);
            $merge      = PairMerge::where('id', $pairMergeId)->lockForUpdate()->first();
            if (null === $merge) {
                throw new PairRefusedException(404, 'not_found');
            }
            if (null !== $merge->unmerged_at) {
                return ['merge' => $merge, 'changed' => false, 'overridden' => []];
            }
            $keepJournal = TransactionJournal::where('id', $merge->keep_journal_id)->first();
            if (null === $keepJournal) {
                throw new PairRefusedException(409, 'keep_missing');
            }
            $keepJ       = (int) $merge->keep_journal_id;
            $this->refuseIfLinksTaken($userGroupId, $merge);
            $this->refuseIfAccountsMissing($userGroupId, $merge);
            $current     = $this->keepState($userGroupId, $keepJ);
            $diverged    = $this->diverged($merge->keep_after, $current);
            if ([] !== $diverged && !$force) {
                throw new PairRefusedException(409, 'diverged', ['fields' => $diverged]);
            }
            $updateObjects = TransactionGroupEventObjects::collectFromTransactionGroup(TransactionGroup::findOrFail($merge->keep_group_id));

            $this->restoreAbsorbed($userGroupId, $merge);
            $this->restoreKeep($userGroupId, $merge, $current);
            $keepDate = (string) DB::table('transaction_journals')->where('id', $keepJ)->value('date');
            $this->flagLaterRows(
                array_merge(array_column($merge->absorbed_snapshot['tables']['transactions'] ?? [], 'account_id'), array_column($this->rowsOf($keepJ)['rows'], 'account_id'), [$merge->keep_before['destination_account_id'] ?? 0]),
                min($keepDate, (string) ($merge->absorbed_snapshot['journal']['date'] ?? $keepDate)),
            );
            self::bumpJournalVersion($keepJ, self::versionOf($keepJ));
            $merge->unmerged_at = Carbon::now();
            $merge->save();

            return ['merge' => $merge, 'changed' => true, 'overridden' => $diverged];
        });
        if ($result['changed'] && null !== $updateObjects) {
            $merge = $result['merge'];
            $this->afterCommit(function () use ($updateObjects, $merge): void {
                $updateObjects->appendFromTransactionGroup(TransactionGroup::findOrFail($merge->keep_group_id));
                $updateObjects->appendFromTransactionGroup(TransactionGroup::findOrFail($merge->absorbed_group_id));
                $this->fireUpdated($updateObjects);
            });
        }

        return $result;
    }

    /**
     * Run work that follows the commit. A database error here must NOT look like a retryable "busy, nothing written"
     * (the controller maps QueryException to 503): the merge or unmerge is committed, so it surfaces as a plain failure
     * and a retry stays idempotent (merge replays, unmerge answers "already unmerged").
     */
    private function afterCommit(\Closure $work): void
    {
        try {
            $work();
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \RuntimeException('The change is committed, but the follow-up work failed (SQLSTATE '.($e->errorInfo[0] ?? '?').').', 0, $e);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // locking and reading
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Postgres advisory locks on the sorted Plaid ids (the same keys every link writer uses), then row locks on the
     * journals and their transactions, journal-id order. Other drivers: only the row locks that they support.
     *
     * @param array<int, int>    $journalIds
     * @param array<int, string> $plaidIds
     */
    private function lock(int $userGroupId, array $journalIds, array $plaidIds): void
    {
        app(PlaidLinkService::class)->lockIds($userGroupId, $plaidIds);
        $journalIds = array_values(array_unique($journalIds));
        sort($journalIds);
        if ([] === $journalIds) {
            return;
        }
        DB::table('transaction_journals')->whereIn('id', $journalIds)->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('transactions')->whereIn('transaction_journal_id', $journalIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * Journal ids (live or soft-deleted: a retry meets an absorbed journal that is already deleted) of the groups.
     *
     * @param array<int, int> $groupIds
     *
     * @return array<int, int>
     */
    private function journalIdsOf(int $userGroupId, array $groupIds): array
    {
        return DB::table('transaction_journals')->where('user_group_id', $userGroupId)->whereIn('transaction_group_id', $groupIds)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param array<int, int> $journalIds
     *
     * @return array<int, string>
     */
    private function plaidIdsOfJournals(int $userGroupId, array $journalIds): array
    {
        if ([] === $journalIds) {
            return [];
        }

        return PlaidTransactionLink::where('user_group_id', $userGroupId)->whereIn('transaction_journal_id', $journalIds)->pluck('plaid_transaction_id')->map(static fn ($id): string => (string) $id)->all();
    }

    /** @throws PairRefusedException */
    private function liveGroup(int $userGroupId, int $groupId): TransactionGroup
    {
        $group = TransactionGroup::where('id', $groupId)->where('user_group_id', $userGroupId)->first();
        if (null === $group) {
            throw new PairRefusedException(404, 'not_found');
        }

        return $group;
    }

    /** @throws PairRefusedException */
    private function singleJournalId(TransactionGroup $group): int
    {
        $ids = DB::table('transaction_journals')->where('transaction_group_id', $group->id)->whereNull('deleted_at')->pluck('id');
        if (1 !== $ids->count()) {
            throw new PairRefusedException(409, 'not_single');
        }

        return (int) $ids->first();
    }

    /**
     * The Plaid ids were locked before the rows were read. If the link set of the two journals is not the one that was
     * locked, a writer slipped in between: refuse rather than work on ids we do not hold.
     *
     * @param array<int, int>    $journalIds
     * @param array<int, string> $lockedPlaidIds
     *
     * @throws PairRefusedException
     */
    private function verifyLocked(int $userGroupId, array $journalIds, array $lockedPlaidIds): void
    {
        $now    = $this->plaidIdsOfJournals($userGroupId, $journalIds);
        $locked = array_values(array_unique($lockedPlaidIds));
        sort($now, SORT_STRING);
        sort($locked, SORT_STRING);
        if ($now !== $locked) {
            throw new PairRefusedException(409, 'link_conflict');
        }
    }

    /**
     * @return array{plaid_transaction_id: string, leg: string, plaid_account_id: ?string}
     *
     * @throws PairRefusedException
     */
    private function singleLink(int $userGroupId, int $journalId): array
    {
        $links = PlaidTransactionLink::where('user_group_id', $userGroupId)->where('transaction_journal_id', $journalId)->get();
        if (1 !== $links->count() || 'single' !== $links->first()->leg) {
            throw new PairRefusedException(409, 'not_single');
        }
        $link = $links->first();

        return ['plaid_transaction_id' => $link->plaid_transaction_id, 'leg' => $link->leg, 'plaid_account_id' => $link->plaid_account_id];
    }

    /** @throws PairRefusedException */
    private function refuseIfStateWouldBeLost(int $journalId): void
    {
        if (DB::table('transactions')->where('transaction_journal_id', $journalId)->whereNull('deleted_at')->where('reconciled', true)->exists()) {
            throw new PairRefusedException(409, 'reconciled');
        }
        $morph = (new TransactionJournal())->getMorphClass();
        if (DB::table('attachments')->where('attachable_id', $journalId)->where('attachable_type', $morph)->whereNull('deleted_at')->exists()) {
            throw new PairRefusedException(409, 'attachments');
        }
        if (DB::table('piggy_bank_events')->where('transaction_journal_id', $journalId)->exists()) {
            throw new PairRefusedException(409, 'piggy_bank');
        }
        if (DB::table('journal_links')->where(static function ($q) use ($journalId): void {
            $q->where('source_id', $journalId)->orWhere('destination_id', $journalId);
        })->exists()) {
            throw new PairRefusedException(409, 'journal_links');
        }
    }

    /** @throws PairRefusedException */
    private function refuseIfStale(int $journalId, string $sent, string $side): void
    {
        // Every bump writes the same stamp to the journal and its group. They can still differ (a journal saved a second
        // after its group at creation, rows written by older code). The client reads the GROUP's stamp from the API, so
        // that one is accepted; so is the later of the two, which the refusal below hands back as current_updated_at.
        $stamps  = self::stampsOf($journalId);
        $current = self::versionOf($journalId);
        $sentAt  = Carbon::parse($sent)->getTimestamp();
        if (null === $stamps || null === $current || ($sentAt !== $current->getTimestamp() && $sentAt !== $stamps['group']?->getTimestamp())) {
            throw new PairRefusedException(409, 'stale', ['side' => $side, 'current_updated_at' => $current?->toAtomString()]);
        }
    }

    /**
     * The 422 rules. Returns the resulting transaction type name.
     *
     * @throws PairRefusedException
     */
    private function validateDomain(int $keepJ, int $absJ): string
    {
        $keep = $this->rowsOf($keepJ);
        $abs  = $this->rowsOf($absJ);
        if (2 !== count($keep['rows']) || 2 !== count($abs['rows'])) {
            throw new PairRefusedException(409, 'not_single');
        }
        if (TransactionTypeEnum::WITHDRAWAL->value !== $keep['type'] || TransactionTypeEnum::DEPOSIT->value !== $abs['type']) {
            throw new PairRefusedException(422, 'direction');
        }
        $keepSource = $keep['source'];
        $absDest    = $abs['destination'];
        if (null === $keepSource || null === $absDest || null === $keep['destination'] || null === $abs['source']) {
            throw new PairRefusedException(422, 'direction');
        }
        if (!in_array($keepSource->account_type, self::OWN_TYPES, true) || !in_array($absDest->account_type, self::OWN_TYPES, true)) {
            throw new PairRefusedException(422, 'not_own_accounts');
        }
        if ((int) $keepSource->account_id === (int) $absDest->account_id) {
            throw new PairRefusedException(422, 'same_account');
        }
        $currencies = [];
        foreach (array_merge($keep['rows'], $abs['rows']) as $row) {
            if (null !== $row->foreign_amount || null !== $row->foreign_currency_id) {
                throw new PairRefusedException(422, 'currency_mismatch');
            }
            $currencies[] = (int) $row->transaction_currency_id;
        }
        if (1 !== count(array_unique($currencies))) {
            throw new PairRefusedException(422, 'currency_mismatch');
        }
        $amount = $this->abs((string) $keepSource->amount);
        foreach (array_merge($keep['rows'], $abs['rows']) as $row) {
            if (0 !== bccomp($amount, $this->abs((string) $row->amount), 12)) {
                throw new PairRefusedException(422, 'amount_mismatch');
            }
        }
        $table = config('firefly.account_to_transaction');
        $type  = $table[$keepSource->account_type][$absDest->account_type] ?? null;
        if (!in_array($type, [TransactionTypeEnum::TRANSFER->value, TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value], true)) {
            throw new PairRefusedException(422, 'type_not_possible');
        }

        return $type;
    }

    private function abs(string $amount): string
    {
        return ltrim($amount, '-+');
    }

    /** @return array{type: string, rows: array<int, object>, source: ?object, destination: ?object} */
    private function rowsOf(int $journalId): array
    {
        $type = (string) DB::table('transaction_journals as j')->join('transaction_types as tt', 'tt.id', '=', 'j.transaction_type_id')->where('j.id', $journalId)->value('tt.type');
        $rows = DB::table('transactions as t')
            ->join('accounts as a', 'a.id', '=', 't.account_id')
            ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
            ->where('t.transaction_journal_id', $journalId)
            ->whereNull('t.deleted_at')
            ->orderBy('t.id')
            ->get(['t.*', 'at.type as account_type'])
            ->all()
        ;
        $source = null;
        $dest   = null;
        foreach ($rows as $row) {
            if (bccomp((string) $row->amount, '0', 12) < 0) {
                $source = $row;
            }
            if (bccomp((string) $row->amount, '0', 12) > 0) {
                $dest = $row;
            }
        }

        return ['type' => $type, 'rows' => $rows, 'source' => $source, 'destination' => $dest];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // state of keep, snapshot of absorbed
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Everything the merge can change on keep, in a form that can be compared and restored.
     *
     * @return array<string, mixed>
     */
    private function keepState(int $userGroupId, int $journalId): array
    {
        $journal = DB::table('transaction_journals')->where('id', $journalId)->first();
        $dest    = DB::table('transactions')->where('transaction_journal_id', $journalId)->whereNull('deleted_at')->where('amount', '>', 0)->first();
        $morph   = (new TransactionJournal())->getMorphClass();
        $note    = DB::table('notes')->where('noteable_id', $journalId)->where('noteable_type', $morph)->whereNull('deleted_at')->orderBy('id')->first();
        $ids     = static function (string $table, string $column) use ($journalId): array {
            $values = DB::table($table)->where('transaction_journal_id', $journalId)->pluck($column)->map(static fn ($v): int => (int) $v)->all();
            sort($values);

            return $values;
        };
        $links   = PlaidTransactionLink::where('user_group_id', $userGroupId)->where('transaction_journal_id', $journalId)->orderBy('plaid_transaction_id')->get()
            ->map(static fn (PlaidTransactionLink $l): array => ['plaid_transaction_id' => $l->plaid_transaction_id, 'leg' => $l->leg, 'plaid_account_id' => $l->plaid_account_id])->values()->all()
        ;

        return [
            'transaction_type_id'        => (int) $journal->transaction_type_id,
            'tag_count'                  => (int) $journal->tag_count,
            'bill_id'                    => null === $journal->bill_id ? null : (int) $journal->bill_id,
            'updated_at'                 => $journal->updated_at,
            'destination_transaction_id' => null === $dest ? null : (int) $dest->id,
            'destination_account_id'     => null === $dest ? null : (int) $dest->account_id,
            'tag_ids'                    => $ids('tag_transaction_journal', 'tag_id'),
            'category_ids'               => $ids('category_transaction_journal', 'category_id'),
            'budget_ids'                 => $ids('budget_transaction_journal', 'budget_id'),
            'notes'                      => $note?->text,
            'plaid_links'                => $links,
        ];
    }

    /**
     * Which merge-changed fields of keep differ from what the merge left. Tags never diverge: they are restored by
     * removing exactly what the merge added, so an edit of the tags since then survives.
     *
     * @param array<string, mixed> $after
     * @param array<string, mixed> $current
     *
     * @return array<int, array{field: string, expected: mixed, current: mixed}>
     */
    private function diverged(array $after, array $current): array
    {
        $fields = [
            'type'                => 'transaction_type_id',
            'destination_account' => 'destination_account_id',
            'bill'                => 'bill_id',
            'notes'               => 'notes',
            'category'            => 'category_ids',
            'budget'              => 'budget_ids',
            'plaid_links'         => 'plaid_links',
        ];
        $out    = [];
        foreach ($fields as $name => $key) {
            if ($after[$key] !== $current[$key]) {
                $out[] = ['field' => $name, 'expected' => $after[$key], 'current' => $current[$key]];
            }
        }

        return $out;
    }

    /**
     * The absorbed journal in full: every row the soft delete hides or removes, as raw rows.
     *
     * @return array<string, mixed>
     */
    private function snapshot(int $userGroupId, TransactionGroup $group, int $journalId): array
    {
        $snapshot = [
            'group'   => (array) DB::table('transaction_groups')->where('id', $group->id)->first(),
            'journal' => (array) DB::table('transaction_journals')->where('id', $journalId)->first(),
            'tables'  => [],
        ];
        foreach (array_keys(self::JOURNAL_TABLES) as $table) {
            $snapshot['tables'][$table] = $this->liveRows($table, $journalId);
        }
        $snapshot['plaid_links'] = DB::table('plaid_transaction_links')->where('user_group_id', $userGroupId)->where('transaction_journal_id', $journalId)->get()->map(static fn ($r): array => (array) $r)->all();

        return $snapshot;
    }

    /** @return array<int, array<string, mixed>> */
    private function liveRows(string $table, int $journalId): array
    {
        [$column, $typeColumn] = self::JOURNAL_TABLES[$table];
        $query                 = DB::table($table)->where($column, $journalId)->orderBy('id');
        if (null !== $typeColumn) {
            $query->where($typeColumn, (new TransactionJournal())->getMorphClass());
        }
        if (in_array($table, ['transactions', 'journal_meta', 'notes', 'locations', 'audit_log_entries'], true)) {
            $query->whereNull('deleted_at');
        }

        return $query->get()->map(static fn ($r): array => (array) $r)->all();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // merge: apply to keep
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @param array{plaid_transaction_id: string, leg: string, plaid_account_id: ?string} $keepLink
     * @param array{plaid_transaction_id: string, leg: string, plaid_account_id: ?string} $absLink
     * @param array<string, mixed>                                                        $absorbedRow
     *
     * @throws PairRefusedException
     */
    private function applyToKeep(int $userGroupId, int $keepJ, int $absJ, string $typeName, array $keepLink, array $absLink, array $absorbedRow): void
    {
        $keep     = $this->keepState($userGroupId, $keepJ);
        $absorbed = $this->keepState($userGroupId, $absJ);
        $abs      = $this->rowsOf($absJ);
        $typeId   = (int) DB::table('transaction_types')->where('type', $typeName)->value('id');
        $now      = Carbon::now()->toDateTimeString();

        // step 5: type, destination row account, link legs
        DB::table('transactions')->where('id', $keep['destination_transaction_id'])->update(['account_id' => (int) $abs['destination']->account_id, 'updated_at' => $now, 'balance_dirty' => true]);
        DB::table('transactions')->where('transaction_journal_id', $keepJ)->update(['balance_dirty' => true]);
        $changed = PlaidTransactionLink::where('user_group_id', $userGroupId)->where('plaid_transaction_id', $keepLink['plaid_transaction_id'])->where('transaction_journal_id', $keepJ)->where('leg', 'single')->update(['leg' => 'source']);
        $moved   = PlaidTransactionLink::where('user_group_id', $userGroupId)->where('plaid_transaction_id', $absLink['plaid_transaction_id'])->where('transaction_journal_id', $absJ)->where('leg', 'single')->update(['transaction_journal_id' => $keepJ, 'leg' => 'destination']);
        if (1 !== $changed || 1 !== $moved) {
            throw new PairRefusedException(409, 'link_conflict');
        }

        // step 6: user fields
        $tagsToAdd = array_values(array_diff($absorbed['tag_ids'], $keep['tag_ids']));
        foreach ($tagsToAdd as $tagId) {
            DB::table('tag_transaction_journal')->insert(['tag_id' => $tagId, 'transaction_journal_id' => $keepJ]);
        }
        // tag_count is a legacy column Firefly does not maintain (always 0), so it is not touched.
        $update    = ['transaction_type_id' => $typeId];
        if (null === $keep['bill_id'] && null !== $absorbed['bill_id']) {
            $update['bill_id'] = $absorbed['bill_id'];
        }
        DB::table('transaction_journals')->where('id', $keepJ)->update($update);
        if ([] === $keep['category_ids']) {
            foreach ($absorbed['category_ids'] as $categoryId) {
                DB::table('category_transaction_journal')->insert(['category_id' => $categoryId, 'transaction_journal_id' => $keepJ]);
            }
        }
        if ([] === $keep['budget_ids']) {
            foreach ($absorbed['budget_ids'] as $budgetId) {
                DB::table('budget_transaction_journal')->insert(['budget_id' => $budgetId, 'transaction_journal_id' => $keepJ]);
            }
        }
        $this->mergeNotes($keepJ, $keep['notes'], $absorbed['notes'], $absorbedRow);
    }

    /**
     * @param array<string, mixed> $absorbedRow
     */
    private function mergeNotes(int $keepJ, ?string $keepText, ?string $absorbedText, array $absorbedRow): void
    {
        if (null === $absorbedText || '' === trim($absorbedText) || $absorbedText === $keepText) {
            return;
        }
        $date    = Carbon::parse((string) $absorbedRow['date'])->format('Y-m-d');
        $block   = sprintf("--- merged from %s %s ---\n%s", $date, $absorbedRow['description'], $absorbedText);
        $merged  = null === $keepText || '' === trim($keepText) ? $block : $keepText."\n\n".$block;
        $morph   = (new TransactionJournal())->getMorphClass();
        $noteId  = DB::table('notes')->where('noteable_id', $keepJ)->where('noteable_type', $morph)->whereNull('deleted_at')->orderBy('id')->value('id');
        if (null === $noteId) {
            DB::table('notes')->insert(['noteable_id' => $keepJ, 'noteable_type' => $morph, 'text' => $merged, 'created_at' => Carbon::now()->toDateTimeString(), 'updated_at' => Carbon::now()->toDateTimeString()]);

            return;
        }
        DB::table('notes')->where('id', $noteId)->update(['text' => $merged, 'updated_at' => Carbon::now()->toDateTimeString()]);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // unmerge
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Bring the absorbed journal back: update rows that still exist (soft deleted), insert rows that were purged.
     * Original ids are reused; they cannot be taken by anyone else (sequences only move forward).
     *
     * @throws PairRefusedException
     */
    private function restoreAbsorbed(int $userGroupId, PairMerge $merge): void
    {
        $snapshot = $merge->absorbed_snapshot;
        $this->upsert('transaction_groups', $snapshot['group']);
        $this->upsert('transaction_journals', $snapshot['journal']);
        // one stamp for journal and group, also for snapshots taken before every bump wrote both
        $later = max((string) $snapshot['journal']['updated_at'], (string) $snapshot['group']['updated_at']);
        DB::table('transaction_journals')->where('id', $snapshot['journal']['id'])->update(['updated_at' => $later]);
        DB::table('transaction_groups')->where('id', $snapshot['group']['id'])->update(['updated_at' => $later]);
        foreach (self::JOURNAL_TABLES as $table => $unused) {
            foreach ($snapshot['tables'][$table] ?? [] as $row) {
                $this->upsert($table, $row);
            }
        }
        // the Plaid id: moved back from keep, or re-created when keep no longer holds it.
        foreach ($snapshot['plaid_links'] as $link) {
            $key      = ['user_group_id' => $userGroupId, 'plaid_transaction_id' => $link['plaid_transaction_id']];
            $existing = PlaidTransactionLink::where($key)->first();
            if (null === $existing) {
                DB::table('plaid_transaction_links')->insert($link);

                continue;
            }
            if ($existing->transaction_journal_id !== (int) $merge->keep_journal_id) {
                throw new PairRefusedException(409, 'link_conflict');
            }
            $moved = PlaidTransactionLink::where($key)->where('transaction_journal_id', $merge->keep_journal_id)->update(['transaction_journal_id' => $merge->absorbed_journal_id, 'leg' => $link['leg'], 'plaid_account_id' => $link['plaid_account_id']]);
            if (1 !== $moved) {
                throw new PairRefusedException(409, 'link_conflict');
            }
        }
    }

    /**
     * A Plaid id that belongs to this pair but now sits on some OTHER journal (it was removed from keep and imported
     * again) cannot be put back: refuse before anything is changed, force or not.
     *
     * @throws PairRefusedException
     */
    private function refuseIfLinksTaken(int $userGroupId, PairMerge $merge): void
    {
        $mine = [(int) $merge->keep_journal_id, (int) $merge->absorbed_journal_id];
        $ids  = array_merge(array_column($merge->absorbed_snapshot['plaid_links'] ?? [], 'plaid_transaction_id'), array_column($merge->keep_before['plaid_links'] ?? [], 'plaid_transaction_id'));
        foreach (PlaidTransactionLink::where('user_group_id', $userGroupId)->whereIn('plaid_transaction_id', $ids)->get() as $link) {
            if (!in_array($link->transaction_journal_id, $mine, true)) {
                throw new PairRefusedException(409, 'link_conflict', ['conflicts' => [app(PlaidLinkService::class)->describe($link)]]);
            }
        }
    }

    /**
     * Every account the restore would write a transaction onto must still be live (not soft deleted, same user group):
     * the payee naming pass merges and deletes counter accounts. Refused before anything is changed, force or not.
     *
     * @throws PairRefusedException
     */
    private function refuseIfAccountsMissing(int $userGroupId, PairMerge $merge): void
    {
        $ids = array_map('intval', array_column($merge->absorbed_snapshot['tables']['transactions'] ?? [], 'account_id'));
        if (null !== ($merge->keep_before['destination_account_id'] ?? null)) {
            $ids[] = (int) $merge->keep_before['destination_account_id'];
        }
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return;
        }
        // a NULL user_group_id (very old accounts) is accepted: the snapshot already names these accounts
        $live    = DB::table('accounts')->where(static fn ($q) => $q->where('user_group_id', $userGroupId)->orWhereNull('user_group_id'))->whereNull('deleted_at')->whereIn('id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $missing = array_values(array_diff($ids, $live));
        sort($missing);
        if ([] !== $missing) {
            throw new PairRefusedException(409, 'account_missing', ['accounts' => $missing]);
        }
    }

    /**
     * Flag the live rows of the given accounts dated on or after $from, so the nightly recalculation repairs their
     * running balances even if the post-commit listeners never run.
     *
     * @param array<int, int|string> $accountIds
     */
    private function flagLaterRows(array $accountIds, string $from): void
    {
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
        if ([] === $accountIds) {
            return;
        }
        // SKIP LOCKED: these rows belong to other journals, so a concurrent merge on the same account may hold some of
        // them; waiting for it while it waits for ours would be a deadlock. A skipped row is being rewritten by that
        // merge's own recalculation.
        $ids = DB::table('transactions')
            ->whereNull('deleted_at')
            ->whereIn('account_id', $accountIds)
            ->whereIn('transaction_journal_id', static fn ($q) => $q->select('id')->from('transaction_journals')->whereNull('deleted_at')->where('date', '>=', $from))
            ->orderBy('id')
            ->lock('for update skip locked')
            ->pluck('id')
        ;
        if ($ids->isNotEmpty()) {
            DB::table('transactions')->whereIn('id', $ids->all())->update(['balance_dirty' => true]);
        }
    }

    /** @param array<string, mixed> $row */
    private function upsert(string $table, array $row): void
    {
        $exists = DB::table($table)->where('id', $row['id'])->exists();
        if ($exists) {
            $values = $row;
            unset($values['id']);
            DB::table($table)->where('id', $row['id'])->update($values);

            return;
        }
        DB::table($table)->insert($row);
    }

    /**
     * Restore only what the merge changed on keep. Edits to other fields (description, date, ...) are untouched.
     *
     * @param array<string, mixed> $current keep's state read before anything was restored
     *
     * @throws PairRefusedException
     */
    private function restoreKeep(int $userGroupId, PairMerge $merge, array $current): void
    {
        $keepJ  = (int) $merge->keep_journal_id;
        $before = $merge->keep_before;
        $after  = $merge->keep_after;

        // tags: remove exactly what the merge added.
        $added = array_values(array_diff($after['tag_ids'], $before['tag_ids']));
        if ([] !== $added) {
            DB::table('tag_transaction_journal')->where('transaction_journal_id', $keepJ)->whereIn('tag_id', $added)->delete();
        }
        DB::table('transaction_journals')->where('id', $keepJ)->update([
            'transaction_type_id' => $before['transaction_type_id'],
            'bill_id'             => $before['bill_id'],
        ]);
        DB::table('transactions')->where('id', $before['destination_transaction_id'])->update(['account_id' => $before['destination_account_id'], 'balance_dirty' => true]);
        DB::table('transactions')->where('transaction_journal_id', $keepJ)->update(['balance_dirty' => true]);

        $this->restorePivot('category_transaction_journal', 'category_id', $keepJ, $before['category_ids']);
        $this->restorePivot('budget_transaction_journal', 'budget_id', $keepJ, $before['budget_ids']);

        $morph = (new TransactionJournal())->getMorphClass();
        if (null === $before['notes']) {
            DB::table('notes')->where('noteable_id', $keepJ)->where('noteable_type', $morph)->whereNull('deleted_at')->delete();
        }
        if (null !== $before['notes']) {
            DB::table('notes')->where('noteable_id', $keepJ)->where('noteable_type', $morph)->whereNull('deleted_at')->update(['text' => $before['notes']]);
        }

        // keep's own link back to leg single (the absorbed id was moved back by restoreAbsorbed).
        foreach ($before['plaid_links'] as $link) {
            $key      = ['user_group_id' => $userGroupId, 'plaid_transaction_id' => $link['plaid_transaction_id']];
            $existing = PlaidTransactionLink::where($key)->first();
            if (null === $existing) {
                PlaidTransactionLink::create($key + ['transaction_journal_id' => $keepJ, 'leg' => $link['leg'], 'plaid_account_id' => $link['plaid_account_id']]);

                continue;
            }
            if ($existing->transaction_journal_id !== $keepJ) {
                throw new PairRefusedException(409, 'link_conflict');
            }
            PlaidTransactionLink::where($key)->where('transaction_journal_id', $keepJ)->update(['leg' => $link['leg'], 'plaid_account_id' => $link['plaid_account_id']]);
        }
    }

    /**
     * @param array<int, int> $wanted
     */
    private function restorePivot(string $table, string $column, int $journalId, array $wanted): void
    {
        $query = DB::table($table)->where('transaction_journal_id', $journalId);
        if ([] !== $wanted) {
            $query->whereNotIn($column, $wanted);
        }
        $query->delete();
        $have = DB::table($table)->where('transaction_journal_id', $journalId)->pluck($column)->map(static fn ($v): int => (int) $v)->all();
        foreach (array_diff($wanted, $have) as $id) {
            DB::table($table)->insert([$column => $id, 'transaction_journal_id' => $journalId]);
        }
    }

    private function fireUpdated(TransactionGroupEventObjects $objects): void
    {
        $flags             = new TransactionGroupEventFlags();
        $flags->applyRules = false;
        event(new UpdatedSingleTransactionGroup($flags, $objects));
        event(new WebhookMessagesRequestSending());
    }
}
