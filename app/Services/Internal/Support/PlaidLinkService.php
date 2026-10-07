<?php

/*
 * PlaidLinkService.php
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

namespace FireflyIII\Services\Internal\Support;

use FireflyIII\Exceptions\PlaidLinkConflictException;
use FireflyIII\Models\PlaidTransactionLink;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the Plaid links of a journal. Always call this inside a database transaction
 * that also holds the journal write, so a conflict leaves nothing behind.
 */
class PlaidLinkService
{
    /**
     * Make the links of this journal equal to $links (the full desired set).
     * Links not in $links are removed, so replacing an id (pending to posted) is just sending the new id.
     *
     * @param array<int, array{plaid_transaction_id: string, leg: string, plaid_account_id: ?string}> $links
     *
     * @throws PlaidLinkConflictException
     */
    public function sync(TransactionJournal $journal, array $links): void
    {
        $groupId   = $journal->user_group_id;
        // fixed lock order: two concurrent requests with the same ids in opposite order cannot deadlock.
        usort($links, static fn (array $a, array $b): int => strcmp($a['plaid_transaction_id'], $b['plaid_transaction_id']));
        $wanted    = array_column($links, 'plaid_transaction_id');
        PlaidTransactionLink::where('user_group_id', $groupId)
            ->where('transaction_journal_id', $journal->id)
            ->whereNotIn('plaid_transaction_id', $wanted)
            ->delete()
        ;

        $conflicts = [];
        foreach ($links as $link) {
            $conflict = $this->writeLink($groupId, $journal, $link);
            if (null !== $conflict) {
                $conflicts[] = $conflict;
            }
        }
        if ([] !== $conflicts) {
            throw new PlaidLinkConflictException($conflicts);
        }
    }

    /**
     * Take a transaction-scoped Postgres advisory lock for every Plaid id of a whole request, in sorted order,
     * before any link row is touched. Two requests with the same ids in opposite order across splits then queue
     * instead of deadlocking (R2-5). Other drivers: no-op. Call inside the database transaction.
     *
     * @param array<int, string> $ids
     */
    public function lockIds(int $groupId, array $ids): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            return;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$groupId.':'.$id]);
        }
    }

    /**
     * Free every id of $splits that currently sits on a DIFFERENT journal of this group than the split that
     * wants it, so that moving an id between splits works in any split order (R2-1). The per-journal sync then
     * inserts it on the right journal. Ids on journals of other groups are never touched.
     *
     * @param array<int, array<string, mixed>> $splits   submitted splits (with optional transaction_journal_id and plaid_links)
     * @param array<int, int>                  $journalIds ids of the journals of the group being updated
     */
    public function releaseMovedIds(int $groupId, array $journalIds, array $splits): void
    {
        foreach ($splits as $split) {
            if (!is_array($split['plaid_links'] ?? null) || [] === $split['plaid_links']) {
                continue;
            }
            $ids = array_column($split['plaid_links'], 'plaid_transaction_id');
            PlaidTransactionLink::where('user_group_id', $groupId)
                ->whereIn('transaction_journal_id', $journalIds)
                ->whereIn('plaid_transaction_id', $ids)
                ->where('transaction_journal_id', '!=', (int) ($split['transaction_journal_id'] ?? 0))
                ->delete()
            ;
        }
    }

    /**
     * @return null|array{plaid_transaction_id: string, transaction_journal_id: ?int, transaction_group_id: ?int, leg: string}
     */
    private function writeLink(int $groupId, TransactionJournal $journal, array $link, bool $retried = false): ?array
    {
        $attributes = ['leg' => $link['leg'], 'plaid_account_id' => $link['plaid_account_id'] ?? null, 'transaction_journal_id' => $journal->id];
        $key        = ['user_group_id' => $groupId, 'plaid_transaction_id' => $link['plaid_transaction_id']];
        $existing   = PlaidTransactionLink::where($key)->first();
        if (null !== $existing && $existing->transaction_journal_id !== $journal->id) {
            return $this->describe($existing);
        }
        if (null !== $existing) {
            // keyed query-builder update: the table has a composite key, so Model::update() would
            // emit "where id is null" (500 on Postgres, silent no-op on SQLite). Never call
            // save()/update()/delete() on a PlaidTransactionLink instance.
            // conditioned on the journal still owning the row (R2-4): a concurrent move makes this match 0 rows.
            $changed = PlaidTransactionLink::where($key)->where('transaction_journal_id', $journal->id)->update($attributes);
            if (1 === $changed) {
                return null;
            }
            $now = PlaidTransactionLink::where($key)->first();
            if (null !== $now) {
                return $this->describe($now);
            }

            // the row vanished under us: fall through and insert it.
        }

        try {
            // savepoint: on Postgres a failed insert would otherwise poison the whole transaction.
            DB::transaction(static function () use ($key, $attributes): void {
                PlaidTransactionLink::create($key + $attributes);
            });
        } catch (UniqueConstraintViolationException) {
            // lost a race with a concurrent request that committed the same id first.
            $winner = PlaidTransactionLink::where($key)->first();
            if (null !== $winner && $winner->transaction_journal_id === $journal->id) {
                // a concurrent identical request already wrote what we wanted: desired state holds (R2-6a).
                return $this->writeLink($groupId, $journal, $link, true);
            }
            if (null === $winner && !$retried && 'mysql' !== DB::connection()->getDriverName()) {
                // the winner was deleted again before we could read it: the id is free, try once more (R2-6b).
                return $this->writeLink($groupId, $journal, $link, true);
            }

            // on MySQL (REPEATABLE READ) the winner may be invisible to this snapshot: still answer 409.
            return null === $winner
                ? ['plaid_transaction_id' => $link['plaid_transaction_id'], 'transaction_journal_id' => null, 'transaction_group_id' => null, 'leg' => $link['leg']]
                : $this->describe($winner);
        }

        return null;
    }

    /**
     * @return array{plaid_transaction_id: string, transaction_journal_id: int, transaction_group_id: int, leg: string}
     */
    public function describe(PlaidTransactionLink $link): array
    {
        return [
            'plaid_transaction_id'   => $link->plaid_transaction_id,
            'transaction_journal_id' => $link->transaction_journal_id,
            'transaction_group_id'   => (int) $link->transactionJournal()->withTrashed()->value('transaction_group_id'),
            'leg'                    => $link->leg,
        ];
    }
}
