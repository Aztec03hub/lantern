<?php

/*
 * PairMergeTest.php
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

namespace Tests\integration\Api\Models\Transaction;

use Carbon\Carbon;
use FireflyIII\Events\Model\TransactionGroup\DestroyedSingleTransactionGroup;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending;
use FireflyIII\Models\Account;
use FireflyIII\Models\PairMerge;
use FireflyIII\Services\Internal\Pair\PairMergeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fixes of core review round 1 (docs/reviews/r1-2026-10-08): one version stamp, unmerge safety, API edges.
 * A class of its own so the test groups of scripts/test-pgsql.sh stay under the 2 minute cap.
 *
 * @internal
 *
 * @coversNothing
 */
final class PairMergeFixesTest extends PairTestCase
{
    // ---------------------------------------------------------------- review R1 (C1) fixes: one version stamp

    private function editGroup(int $group, array $split): void
    {
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [$split]])->assertOk();
    }

    private function bill(string $name): int
    {
        return (int) DB::table('bills')->insertGetId(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => $name, 'amount_min' => 1, 'amount_max' => 2, 'date' => '2026-01-01', 'repeat_freq' => 'monthly', 'skip' => 0, 'match' => strtolower($name), 'automatch' => false, 'active' => true, 'transaction_currency_id' => DB::table('transaction_currencies')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** After any bump the journal and its group hold ONE stamp: the one the API shows is the one the service checks. */
    private function assertOneStamp(int $group, string $message = ''): void
    {
        $journal = (string) DB::table('transaction_journals')->where('transaction_group_id', $group)->value('updated_at');
        $this->assertSame($journal, (string) DB::table('transaction_groups')->where('id', $group)->value('updated_at'), trim($message.' journal and group stamp differ'));
    }

    /** core-1 H1 case 2 (repro testB): two PUTs in the same second, then a merge with the stamps GET /transactions/{id} shows. */
    public function testEditsInTheSameSecondThenMergeWithTheStampTheApiShows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00'));

        try {
            [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
            $this->editGroup($keep['group'], ['description' => 'edited']);
            $this->editGroup($keep['group'], ['description' => 'edited again']);
            $this->assertOneStamp($keep['group'], 'after two PUTs');
            $this->merge($this->ref($keep['group']), $this->ref($abs['group']))->assertOk();
            $this->assertOneStamp($keep['group'], 'after the merge');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** core-1 H1 case 1 (repro testC): merge, unmerge five seconds later, merge again with the API stamps. */
    public function testRemergeAfterUnmergeWithTheStampTheApiShows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00'));

        try {
            [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
            $stamps       = $this->stamps();
            $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
            Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:05'));
            $this->unmerge($id)->assertOk();
            $this->assertOneStamp($keep['group'], 'keep after the unmerge');
            $this->assertOneStamp($abs['group'], 'absorbed after the unmerge');
            $this->assertStampsNotOlder($stamps, 'unmerge');
            $this->assertSame('2026-10-08T12:00:05', substr($this->ref($keep['group'])['at'], 0, 19), 'the API shows the unmerge stamp');
            $this->merge($this->ref($keep['group']), $this->ref($abs['group']))->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }

    /** core-1 L4: the stamps travel through the API under the production timezone and merge. */
    public function testMergeWithTheApiStampsUnderTheChicagoTimezone(): void
    {
        $old = date_default_timezone_get();
        config(['app.timezone' => 'America/Chicago']);
        date_default_timezone_set('America/Chicago');

        try {
            [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
            $this->editGroup($keep['group'], ['description' => 'edited']);
            $this->merge($this->ref($keep['group']), $this->ref($abs['group']))->assertOk();
        } finally {
            config(['app.timezone' => $old]);
            date_default_timezone_set($old);
        }
    }

    /** core-3 L1: a stamp NEWER than the server's, or the journal's own stamp while the group is ahead, is stale too. */
    public function testStampNewerThanTheServersOrBehindTheGroupIsStale(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $server       = Carbon::parse($this->serverVersion($keep['group']));
        $this->merge($keep, $abs, ['keep_updated_at' => $server->copy()->addDay()->toAtomString()])->assertStatus(409)->assertJsonPath('reason', 'stale');
        $this->merge($keep, $abs, ['keep_updated_at' => $server->copy()->addSecond()->toAtomString()])->assertStatus(409)->assertJsonPath('reason', 'stale');
        DB::table('transaction_groups')->where('id', $keep['group'])->update(['updated_at' => $server->copy()->addSecond()->toDateTimeString()]);
        $this->merge($keep, $abs)->assertStatus(409)->assertJsonPath('reason', 'stale');
        $this->assertSame(0, PairMerge::count());
    }

    /** core-2 M1: the stale body names the side and the stamp to send, and sending it works. */
    public function testStaleBodyCarriesTheStampToSend(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->editGroup($abs['group'], ['description' => 'edited absorbed']);
        $res = $this->merge($keep, $abs)->assertStatus(409);
        $res->assertJsonPath('reason', 'stale')->assertJsonPath('side', 'absorb');
        $this->assertSame($this->ref($abs['group'])['at'], $res->json('current_updated_at'));
        $this->merge($keep, $abs, ['absorb_updated_at' => $res->json('current_updated_at')])->assertOk();
    }

    /** core-3 L6: nextVersion, versionOf and bumpJournalVersion directly. */
    public function testVersionHelpers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:10'));

        try {
            $this->assertSame('2026-10-08 12:00:10', PairMergeService::nextVersion(null)->toDateTimeString());
            $this->assertSame('2026-10-08 12:00:10', PairMergeService::nextVersion('2026-10-08 11:00:00')->toDateTimeString(), 'a past stamp: now');
            $this->assertSame('2026-10-08 12:00:10', PairMergeService::nextVersion('2026-10-08 12:00:09')->toDateTimeString(), 'a stamp one second back: now');
            $this->assertSame('2026-10-08 12:00:11', PairMergeService::nextVersion('2026-10-08 12:00:10')->toDateTimeString(), 'the same second: one second on');
            $this->assertSame('2026-10-08 12:30:01', PairMergeService::nextVersion('2026-10-08 12:30:00')->toDateTimeString(), 'a future stamp: one second past it');
            [$keep]    = $this->pair($this->assetA, $this->assetB);
            DB::table('transaction_groups')->where('id', $keep['group'])->update(['updated_at' => '2026-10-08 12:20:00']);
            $this->assertSame('2026-10-08 12:20:00', PairMergeService::versionOf($keep['journal'])?->toDateTimeString(), 'the later of journal and group');
            $next = PairMergeService::bumpJournalVersion($keep['journal'], PairMergeService::versionOf($keep['journal']));
            $this->assertSame('2026-10-08 12:20:01', $next->toDateTimeString());
            $this->assertOneStamp($keep['group']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** core-2 L4 (r2): the group's stamp is accepted while the journal is two seconds ahead; the journal's stamp too; group + 1 s is stale. */
    public function testGroupStampArmOfTheStaleCheckWithAJournalAhead(): void
    {
        foreach (['group', 'journal', 'plus-one'] as $n => $case) {
            [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT-'.$n, 'IN-'.$n);
            $group        = Carbon::parse('2026-10-08 12:00:00');
            DB::table('transaction_groups')->where('id', $keep['group'])->update(['updated_at' => $group->toDateTimeString()]);
            DB::table('transaction_journals')->where('id', $keep['journal'])->update(['updated_at' => $group->copy()->addSeconds(2)->toDateTimeString()]);
            $sent = match ($case) {
                'group'    => $group->toAtomString(),
                'journal'  => $group->copy()->addSeconds(2)->toAtomString(),
                'plus-one' => $group->copy()->addSecond()->toAtomString(),
            };
            $res = $this->merge($keep, $abs, ['keep_updated_at' => $sent]);
            'plus-one' === $case ? $res->assertStatus(409)->assertJsonPath('reason', 'stale') : $res->assertOk();
        }
    }

    /** core-2 L1 (r2): a PUT takes the journal row locks BEFORE it reads the previous stamp. */
    public function testPutLocksTheJournalsBeforeReadingTheStamp(): void
    {
        [$keep] = $this->pair($this->assetA, $this->assetB);
        $log    = [];
        DB::listen(static function ($query) use (&$log): void {
            if (str_contains($query->sql, 'transaction_journals')) {
                $log[] = str_contains($query->sql, 'for update') ? 'lock' : (str_starts_with($query->sql, 'select "updated_at"') ? 'stamp' : 'other');
            }
        });
        $this->editGroup($keep['group'], ['description' => 'edited']);
        $this->assertContains('lock', $log);
        $this->assertContains('stamp', $log, implode(',', $log));
        $this->assertLessThan(array_search('stamp', $log, true), array_search('lock', $log, true), implode(',', $log));
    }

    /** core-2 L2 (r2): an account with a NULL user_group_id does not make the unmerge a permanent 409. */
    public function testUnmergeWithAnAccountWithoutAUserGroupWorks(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $accounts     = array_column(PairMerge::findOrFail($id)->absorbed_snapshot['tables']['transactions'], 'account_id');
        $this->assertNotSame([], $accounts);
        DB::table('accounts')->whereIn('id', $accounts)->update(['user_group_id' => null]);
        $this->unmerge($id)->assertOk();
        $this->assertSame(0, $this->liveMerges());
    }

    /** core-2 L3 (r2): a database error in a post-commit listener is not "busy, nothing written": the merge is committed. */
    public function testPostCommitDatabaseErrorIsNotBusy(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->withoutExceptionHandling();
        Event::listen(UpdatedSingleTransactionGroup::class, static function (): void {
            $e = new \PDOException('deadlock detected');
            $e->errorInfo = ['40P01', 7, 'deadlock detected'];

            throw new QueryException('pgsql', 'select 1', [], $e);
        });
        try {
            $this->merge($keep, $abs);
            $this->fail('the listener failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('committed', $e->getMessage());
        }
        $this->assertSame(1, $this->liveMerges());
        // a retry is idempotent
        $this->app->make('events')->forget(UpdatedSingleTransactionGroup::class);
        $this->merge($keep, $abs)->assertOk()->assertJsonPath('data.replayed', true);
    }

    /** core-2 L3 (r3): the unmerge's own afterCommit call is covered too: a listener database error after the commit is not "busy". */
    public function testPostCommitDatabaseErrorOnUnmergeIsNotBusy(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->withoutExceptionHandling();
        Event::listen(UpdatedSingleTransactionGroup::class, static function (): void {
            $e = new \PDOException('deadlock detected');
            $e->errorInfo = ['40P01', 7, 'deadlock detected'];

            throw new QueryException('pgsql', 'select 1', [], $e);
        });
        try {
            $this->unmerge($id);
            $this->fail('the listener failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('committed', $e->getMessage());
        }
        $this->assertSame(0, $this->liveMerges());
        $this->app->make('events')->forget(UpdatedSingleTransactionGroup::class);
        $this->unmerge($id)->assertOk();
    }

    // ---------------------------------------------------------------- unmerge: accounts, fields, balances

    /** core-2 L5 (r2): restoreAbsorbed writes ONE stamp even when the snapshot's journal stamp is behind its group. */
    public function testRestoreAbsorbedUnifiesAnOldSnapshotsStamps(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $merge        = PairMerge::findOrFail($id);
        $snapshot     = $merge->absorbed_snapshot;
        $snapshot['group']['updated_at']   = '2026-10-08 12:00:05';
        $snapshot['journal']['updated_at'] = '2026-10-08 12:00:00';
        $merge->absorbed_snapshot = $snapshot;
        $merge->save();
        $this->unmerge($id)->assertOk();
        $this->assertOneStamp($abs['group'], 'absorbed group after unmerge');
        $this->assertSame('2026-10-08 12:00:05', (string) DB::table('transaction_journals')->where('id', $abs['journal'])->value('updated_at'));
    }

    /** core-1 M1 (repro testH): a counter account deleted after the merge. 409 with the ids, even with force; nothing changes. */
    public function testUnmergeOntoADeletedAccountIsRefusedEvenWithForce(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $revenue      = (int) DB::table('transactions')->where('transaction_journal_id', $abs['journal'])->where('amount', '<', 0)->value('account_id');
        DB::table('accounts')->where('id', $revenue)->update(['deleted_at' => now()]);
        $before       = $this->dump();
        foreach ([false, true] as $force) {
            $res = $this->unmerge($id, $force)->assertStatus(409);
            $res->assertJsonPath('reason', 'account_missing');
            $this->assertSame([$revenue], $res->json('accounts'));
        }
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertNull(PairMerge::find($id)->unmerged_at);
        $this->assertSame(0, DB::table('transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id')->whereNull('t.deleted_at')->whereNotNull('a.deleted_at')->count());
    }

    /** core-1 M1, the keep side: keep's original expense account was deleted. */
    public function testUnmergeWhenKeepsOriginalDestinationAccountIsGoneIsRefused(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $expense      = (int) DB::table('transactions')->where('transaction_journal_id', $keep['journal'])->where('amount', '>', 0)->value('account_id');
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        DB::table('accounts')->where('id', $expense)->update(['deleted_at' => now()]);
        $this->unmerge($id, true)->assertStatus(409)->assertJsonPath('reason', 'account_missing')->assertJsonPath('accounts', [$expense]);
    }

    /** @return array<string, array{0: string}> */
    public static function divergenceFields(): array
    {
        return ['type' => ['type'], 'destination_account' => ['destination_account'], 'bill' => ['bill'], 'budget' => ['budget']];
    }

    /** core-3 M2: an edit of any merge-changed field after the merge is a 409 naming exactly that field; force restores the pre-merge value. */
    #[DataProvider('divergenceFields')]
    public function testEditOfAMergeChangedFieldIsDivergedAndForceRestoresIt(string $field): void
    {
        $this->budgets('B1', 'B2');
        $budgets      = DB::table('budgets')->orderBy('id')->pluck('id')->map(static fn ($v): int => (int) $v)->all();
        $bills        = [$this->bill('Rent'), $this->bill('Phone')];
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $destination  = static fn (int $journal): int => (int) DB::table('transactions')->where('transaction_journal_id', $journal)->where('amount', '>', 0)->value('account_id');
        $typeOf       = static fn (int $journal): int => (int) DB::table('transaction_journals')->where('id', $journal)->value('transaction_type_id');
        $originalDest = $destination($keep['journal']);
        $originalType = $typeOf($keep['journal']);
        DB::table('transaction_journals')->where('id', $abs['journal'])->update(['bill_id' => $bills[0]]);
        DB::table('budget_transaction_journal')->insert(['budget_id' => $budgets[0], 'transaction_journal_id' => $abs['journal']]);
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->assertSame($bills[0], (int) DB::table('transaction_journals')->where('id', $keep['journal'])->value('bill_id'), 'the merge copied the bill');
        match ($field) {
            'type'                => DB::table('transaction_journals')->where('id', $keep['journal'])->update(['transaction_type_id' => $originalType]),
            'destination_account' => DB::table('transactions')->where('transaction_journal_id', $keep['journal'])->where('amount', '>', 0)->update(['account_id' => $this->loanA->id]),
            'bill'                => DB::table('transaction_journals')->where('id', $keep['journal'])->update(['bill_id' => $bills[1]]),
            'budget'              => DB::table('budget_transaction_journal')->where('transaction_journal_id', $keep['journal'])->update(['budget_id' => $budgets[1]]),
        };
        $before = $this->dump();
        $res    = $this->unmerge($id)->assertStatus(409);
        $this->assertSame([$field], array_column($res->json('fields'), 'field'));
        $this->assertDumpsEqual($before, $this->dump());
        $forced = $this->unmerge($id, true)->assertOk();
        $this->assertSame([$field], array_column($forced->json('data.overridden'), 'field'), 'core-2 L4: the forced 200 lists what was overwritten');
        $this->assertSame($originalType, $typeOf($keep['journal']));
        $this->assertSame($originalDest, $destination($keep['journal']));
        $this->assertNull(DB::table('transaction_journals')->where('id', $keep['journal'])->value('bill_id'));
        $this->assertSame(0, DB::table('budget_transaction_journal')->where('transaction_journal_id', $keep['journal'])->count());
    }

    /** core-3 M2 (m7): the bill the merge copied onto keep is taken off again by a plain unmerge. */
    public function testUnmergeRemovesTheBillTheMergeCopied(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        DB::table('transaction_journals')->where('id', $abs['journal'])->update(['bill_id' => $this->bill('Rent')]);
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->assertNotNull(DB::table('transaction_journals')->where('id', $keep['journal'])->value('bill_id'));
        $res = $this->unmerge($id)->assertOk();
        $this->assertNull(DB::table('transaction_journals')->where('id', $keep['journal'])->value('bill_id'));
        $this->assertArrayNotHasKey('overridden', $res->json('data'));
    }

    /** The running balance of every live row of the account equals the sum of the rows up to it (dates are distinct). */
    private function assertRunningBalance(Account $account, string $message): void
    {
        $rows = DB::table('transactions as t')->join('transaction_journals as j', 'j.id', '=', 't.transaction_journal_id')
            ->where('t.account_id', $account->id)->whereNull('t.deleted_at')->whereNull('j.deleted_at')->orderBy('j.date')->get(['t.amount', 't.balance_after', 'j.date'])
        ;
        $sum  = '0';
        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row->amount, 12);
            $this->assertNotNull($row->balance_after, $message.' '.$account->name.' '.$row->date);
            $this->assertSame(0, bccomp($sum, (string) $row->balance_after, 2), sprintf('%s: %s on %s expected %s got %s', $message, $account->name, $row->date, $sum, $row->balance_after));
        }
    }

    /** core-3 M3 (b): the absorbed money moves to keep's later date; a third row in between is recalculated, on merge and on unmerge. */
    public function testRunningBalancesAfterMergeAndUnmergeWhenTheAbsorbedDateIsEarlier(): void
    {
        $keep  = $this->single(['type' => 'withdrawal', 'account' => $this->assetA, 'plaid' => 'OUT1', 'date' => '2026-10-03']);
        $abs   = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'IN1', 'date' => '2026-10-01']);
        $third = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'MID', 'amount' => '10.00', 'date' => '2026-10-02']);
        $late  = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'LATE', 'amount' => '5.00', 'date' => '2026-10-05']);
        $this->assertRunningBalance($this->assetB, 'before');
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->assertRunningBalance($this->assetA, 'after merge');
        $this->assertRunningBalance($this->assetB, 'after merge');
        $this->assertSame(0, bccomp('10', (string) DB::table('transactions')->where('transaction_journal_id', $third['journal'])->where('amount', '>', 0)->value('balance_after'), 2), 'the money now arrives on keep\'s date, after the third row');
        $this->assertSame(0, bccomp('40', (string) DB::table('transactions')->where('transaction_journal_id', $late['journal'])->where('amount', '>', 0)->value('balance_after'), 2));
        $this->unmerge($id)->assertOk();
        $this->assertRunningBalance($this->assetA, 'after unmerge');
        $this->assertRunningBalance($this->assetB, 'after unmerge');
        $this->assertSame(0, bccomp('35', (string) DB::table('transactions')->where('transaction_journal_id', $third['journal'])->where('amount', '>', 0)->value('balance_after'), 2));
    }

    /** core-1 L5: without the post-commit listeners the affected accounts' later rows are flagged for the nightly recalculation. */
    public function testMergeAndUnmergeFlagLaterRowsDirtyInsideTheTransaction(): void
    {
        Event::fake([UpdatedSingleTransactionGroup::class, DestroyedSingleTransactionGroup::class, WebhookMessagesRequestSending::class]);
        $keep  = $this->single(['type' => 'withdrawal', 'account' => $this->assetA, 'plaid' => 'OUT1', 'date' => '2026-10-03']);
        $abs   = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'IN1', 'date' => '2026-10-01']);
        $early = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'EARLY', 'date' => '2026-09-20']);
        $late  = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'LATE', 'date' => '2026-10-05']);
        $edge  = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'EDGE', 'date' => '2026-10-01']);
        $dirty = static fn (array $ref): bool => (bool) DB::table('transactions')->where('transaction_journal_id', $ref['journal'])->where('amount', '>', 0)->value('balance_dirty');
        DB::table('transactions')->update(['balance_dirty' => false]);
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->assertTrue($dirty($late), 'a later row of the destination account is flagged after the merge');
        $this->assertFalse($dirty($early), 'an earlier row is not');
        $this->assertTrue($dirty($edge), 'a row on the boundary day is flagged');
        DB::table('transactions')->update(['balance_dirty' => false]);
        $this->unmerge($id)->assertOk();
        $this->assertTrue($dirty($late), 'a later row is flagged after the unmerge');
        $this->assertTrue($dirty($edge), 'the boundary row too');
        $this->assertFalse($dirty($early));
    }

    /** core-3 L6: unmerge fires UPDATED once and DESTROYED not at all (merge fired DESTROYED once). */
    public function testUnmergeFiresTheUpdatedEvent(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        Event::fake([UpdatedSingleTransactionGroup::class, DestroyedSingleTransactionGroup::class, WebhookMessagesRequestSending::class]);
        $this->unmerge($id)->assertOk();
        Event::assertDispatchedTimes(UpdatedSingleTransactionGroup::class, 1);
        Event::assertNotDispatched(DestroyedSingleTransactionGroup::class);
        $this->unmerge($id)->assertOk();
        Event::assertDispatchedTimes(UpdatedSingleTransactionGroup::class, 1);
    }

    /** core-1 N1: keep without a positive row is refused as a wrong direction, not half merged. */
    public function testKeepWithoutADestinationRowIs422(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        DB::table('transactions')->where('transaction_journal_id', $keep['journal'])->where('amount', '>', 0)->update(['amount' => '-25.00']);
        $this->assertRefused(422, 'direction', $keep, $abs);
    }

    // ---------------------------------------------------------------- API edges (core-2 L1-L5, N1, N3)

    /** @return array<string, array{0: string}> */
    public static function sameGroupForms(): array
    {
        return ['plus sign' => ['+'], 'plain' => ['']];
    }

    #[DataProvider('sameGroupForms')]
    public function testKeepAndAbsorbBeingTheSameGroupIsRefusedBeforeAnything(string $prefix): void
    {
        [$keep] = $this->pair($this->assetA, $this->assetB);
        $before = $this->dump();
        $res    = $this->postJson(route('api.v1.plaid-links.pair.store'), ['keep_group_id' => (string) $keep['group'], 'absorb_group_id' => $prefix.$keep['group'], 'keep_updated_at' => $keep['at'], 'absorb_updated_at' => $keep['at']])->assertStatus(422);
        $this->assertSame('' === $prefix ? 'invalid_request' : 'same_group', $res->json('reason'));
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertSame(0, PairMerge::count());
    }

    /** core-2 L5: stamps are ISO 8601 with an offset ("Z" included); anything looser is a 422 before any work. */
    public function testStampFormat(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        foreach (['2026-10-01', '2026-10-01 12:00:00', '2026-10-01T12:00:00'] as $bad) {
            $res = $this->merge($keep, $abs, ['keep_updated_at' => $bad])->assertStatus(422);
            $this->assertSame('invalid_request', $res->json('reason'));
            $this->assertArrayHasKey('keep_updated_at', $res->json('errors'));
        }
        $zulu = static fn (string $at): string => Carbon::parse($at)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->merge(['at' => $zulu($keep['at'])] + $keep, ['at' => $zulu($abs['at'])] + $abs)->assertOk();
        foreach (['2026-10-08T17:00:00', '2026-10-08T17:00', '2026-10-08 17:00Z', 'garbage', '2026-10-08T17:00:00.Z'] as $bad) {
            $res = $this->merge($keep, $abs, ['absorb_updated_at' => $bad])->assertStatus(422);
            $this->assertArrayHasKey('absorb_updated_at', $res->json('errors'), $bad);
        }
    }

    /** core-2 L1 (r3): digits-only stamps that are not real dates are a 422, not a 500 from Carbon::parse. */
    public function testCalendarImpossibleStampsAreRefused(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $before       = $this->dump();
        foreach (['2026-13-45T25:61:00Z', '2026-10-08T12:60:00Z', '2026-10-08T12:00:00+99:99'] as $bad) {
            foreach (['keep_updated_at', 'absorb_updated_at'] as $field) {
                $res = $this->merge($keep, $abs, [$field => $bad])->assertStatus(422);
                $this->assertSame('invalid_request', $res->json('reason'), $bad);
                $this->assertArrayHasKey($field, $res->json('errors'), $bad);
            }
        }
        $this->assertDumpsEqual($before, $this->dump());
    }

    /** core-2 H1 (r2): the shapes java.time and Jackson send (zero seconds dropped, always Z, fractions) merge; the instant is what counts. */
    public function testStampShapesRealClientsSendAreAccepted(): void
    {
        $instant = Carbon::parse('2026-10-08 17:00:00', 'UTC'); // = 12:00:00-05:00
        $shapes  = [
            ['2026-10-08T17:00Z', '2026-10-08T17:00Z'],
            ['2026-10-08T17:00:00Z', '2026-10-08T17:00:00Z'],
            ['2026-10-08T12:00-05:00', '2026-10-08T12:00:00-05:00'],
            ['2026-10-08T12:00:00.500-05:00', '2026-10-08T17:00:00.5Z'],
        ];
        foreach ($shapes as $n => [$k, $a]) {
            [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT-'.$n, 'IN-'.$n);
            foreach ([$keep['group'], $abs['group']] as $g) {
                DB::table('transaction_journals')->where('transaction_group_id', $g)->update(['updated_at' => $instant->copy()->timezone(config('app.timezone'))->toDateTimeString()]);
                DB::table('transaction_groups')->where('id', $g)->update(['updated_at' => $instant->copy()->timezone(config('app.timezone'))->toDateTimeString()]);
            }
            $this->merge($keep, $abs, ['keep_updated_at' => $k, 'absorb_updated_at' => $a])->assertOk();
        }
        $this->assertSame(count($shapes), PairMerge::count());
        // 30 seconds off is a different instant
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT-X', 'IN-X');
        $this->merge($keep, $abs, ['keep_updated_at' => '2026-10-08T17:00:30Z'])->assertStatus(409)->assertJsonPath('reason', 'stale');
    }

    /** core-2 L2: evidence that cannot be stored is a 422 before the locks; a normal one is stored. */
    public function testEvidenceIsValidatedBeforeAnyWork(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $before       = $this->dump();
        $huge         = $this->merge($keep, $abs, ['evidence' => ['blob' => str_repeat('x', 70000)]])->assertStatus(422);
        $this->assertArrayHasKey('evidence', $huge->json('errors'));
        $bad = $this->post(route('api.v1.plaid-links.pair.store'), [
            'keep_group_id' => $keep['group'], 'absorb_group_id' => $abs['group'], 'keep_updated_at' => $keep['at'], 'absorb_updated_at' => $abs['at'], 'evidence' => ['a' => "\xFF\xFE"],
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame('invalid_request', $bad->json('reason'));
        $this->assertDumpsEqual($before, $this->dump());
        $this->merge($keep, $abs)->assertOk();
        $this->assertSame(['layer' => 2, 'gap_days' => 1, 'rule_version' => 1], PairMerge::first()->evidence);
    }

    /** core-2 N1: a replay says so; a fresh merge does not. */
    public function testReplayIsFlagged(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->merge($keep, $abs)->assertOk()->assertJsonPath('data.replayed', false);
        $this->merge($keep, $abs)->assertOk()->assertJsonPath('data.replayed', true);
    }

    /** core-2 L3: a deadlock or lock timeout is 503 with retry:true and no SQL; any other database error stays a failure. */
    public function testLockFailuresAreBusyNotInternalErrors(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        foreach (['40P01', '55P03', '40001'] as $state) {
            $this->app->bind(PairMergeService::class, static fn () => new class($state) extends PairMergeService {
                public function __construct(private readonly string $state) {}

                public function merge(int $userGroupId, int $keepGroupId, int $absorbGroupId, string $keepUpdatedAt, string $absorbUpdatedAt, ?array $evidence): array
                {
                    $pdo            = new \PDOException('lock failure');
                    $pdo->errorInfo = [$this->state, 7, 'lock failure'];

                    throw new QueryException('pgsql', 'select secret_column from secret_table', [], $pdo);
                }

                public function unmerge(int $userGroupId, int $pairMergeId, bool $force): array
                {
                    return $this->merge(0, 0, 0, '', '', null);
                }
            });
            foreach ([$this->merge($keep, $abs), $this->unmerge(1)] as $res) {
                $res->assertStatus(503)->assertJsonPath('reason', 'busy')->assertJsonPath('retry', true)->assertHeader('Retry-After', '1');
                $this->assertStringNotContainsString('secret', (string) $res->getContent());
                $this->assertStringNotContainsString('SQL', (string) $res->getContent());
            }
        }
    }

    /** core-2 N3: the auth and input edges. Each refusal changes nothing. */
    public function testAuthAndInputEdges(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $stranger     = $this->otherUser('edge-stranger@email.com');
        $account      = Account::factory()->for($stranger)->withType(\FireflyIII\Enums\AccountTypeEnum::ASSET)->create(['name' => 'Stranger asset', 'user_group_id' => $stranger->user_group_id]);
        $foreign      = $this->fastSingle(['type' => 'deposit', 'account' => $account, 'plaid' => 'FOREIGN', 'user' => $stranger]);
        $before       = $this->dump();
        // keep in my group, absorb in a foreign one: the same 404 as a missing group
        $this->merge($keep, $foreign)->assertStatus(404)->assertJsonPath('reason', 'not_found');
        // a stranger names MY user group in the body or the query: refused, not honoured
        $this->actingAs($stranger, 'api');
        $this->merge($keep, $abs, ['user_group_id' => $this->user->user_group_id])->assertStatus(401);
        $this->postJson(route('api.v1.plaid-links.pair.store').'?user_group_id='.$this->user->user_group_id, ['keep_group_id' => $keep['group'], 'absorb_group_id' => $abs['group'], 'keep_updated_at' => $keep['at'], 'absorb_updated_at' => $abs['at']])->assertStatus(401);
        $this->actingAs($this->user, 'api');
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertSame(0, PairMerge::count());
        // unauthenticated DELETE
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->app['auth']->forgetGuards();
        $this->deleteJson(route('api.v1.plaid-links.pair.destroy', ['pairMerge' => $id]))->assertStatus(401);
        $this->actingAs($this->user, 'api');
        $this->assertSame(1, $this->liveMerges());
        // force is a boolean: "banana" is not force, "1" is
        $this->editGroup($keep['group'], ['category_name' => 'Changed after merge']);
        $this->deleteJson(route('api.v1.plaid-links.pair.destroy', ['pairMerge' => $id]).'?force=banana')->assertStatus(409)->assertJsonPath('reason', 'diverged');
        $this->deleteJson(route('api.v1.plaid-links.pair.destroy', ['pairMerge' => $id]).'?force=1')->assertOk();
    }
}
