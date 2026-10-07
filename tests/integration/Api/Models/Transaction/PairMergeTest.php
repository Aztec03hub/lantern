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

use FireflyIII\Events\Model\TransactionGroup\DestroyedSingleTransactionGroup;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Models\Account;
use FireflyIII\Models\PairMerge;
use FireflyIII\Models\PlaidTransactionLink;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /plaid-links/pair and DELETE /plaid-links/pair/{id} (design transfer-pairer.md sections 5, 6, 10).
 *
 * @internal
 *
 * @coversNothing
 */
final class PairMergeTest extends PairTestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> keep source account, absorbed destination account, resulting type */
    public static function accountPairs(): array
    {
        return [
            'asset to asset is a transfer'           => ['assetA', 'assetB', 'withdrawal'],
            'asset to liability is a withdrawal'     => ['assetA', 'loanA', 'withdrawal'],
            'liability to asset is a deposit'        => ['loanA', 'assetA', 'deposit'],
            'liability to liability is a transfer'   => ['loanA', 'loanB', 'withdrawal'],
        ];
    }

    // ---------------------------------------------------------------- 1. happy path, one test per account_to_transaction row

    #[DataProvider('accountPairs')]
    public function testMergeAppliesTheTypeOfAccountToTransaction(string $from, string $to, string $unused): void
    {
        $expected = config('firefly.account_to_transaction')[$this->{$from}->accountType->type][$this->{$to}->accountType->type];
        [$keep, $abs] = $this->pair($this->{$from}, $this->{$to});
        $response = $this->merge($keep, $abs);
        $response->assertOk();
        $response->assertJsonPath('data.keep_group_id', (string) $keep['group']);
        $response->assertJsonPath('data.absorbed_group_id', (string) $abs['group']);
        $response->assertJsonPath('data.type', strtolower($expected));
        $mergeId = $response->json('data.pair_merge_id');

        // journal type, both transaction rows
        $journal = TransactionJournal::with('transactionType')->find($keep['journal']);
        $this->assertSame($expected, $journal->transactionType->type);
        $rows = Transaction::where('transaction_journal_id', $keep['journal'])->orderBy('amount')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($this->{$from}->id, (int) $rows[0]->account_id);
        $this->assertSame($this->{$to}->id, (int) $rows[1]->account_id);
        $this->assertEquals('-25', (string) $rows[0]->amount + 0);
        $this->assertEquals('25', (string) $rows[1]->amount + 0);
        // both links, with legs
        $this->assertSame(['IN1' => 'destination', 'OUT1' => 'source'], $this->linkLegs($keep['journal']));
        $this->assertSame(2, PlaidTransactionLink::count());
        $this->assertSame('pa-IN1', PlaidTransactionLink::where('plaid_transaction_id', 'IN1')->value('plaid_account_id'));
        // absorbed is soft deleted, group and rows
        $this->assertNotNull(DB::table('transaction_journals')->where('id', $abs['journal'])->value('deleted_at'));
        $this->assertNotNull(DB::table('transaction_groups')->where('id', $abs['group'])->value('deleted_at'));
        $this->assertSame(0, DB::table('transactions')->where('transaction_journal_id', $abs['journal'])->whereNull('deleted_at')->count());
        // the audit row
        $row = PairMerge::findOrFail((int) $mergeId);
        $this->assertSame($this->user->user_group_id, $row->user_group_id);
        $this->assertSame($keep['group'], $row->keep_group_id);
        $this->assertSame($keep['journal'], $row->keep_journal_id);
        $this->assertSame($abs['group'], $row->absorbed_group_id);
        $this->assertSame($abs['journal'], $row->absorbed_journal_id);
        $this->assertNull($row->unmerged_at);
        $this->assertNotNull($row->merged_at);
        $this->assertSame(['layer' => 2, 'gap_days' => 1, 'rule_version' => 1], $row->evidence);
        $this->assertSame(7, $row->keep_before['transaction_type_id']);
        $this->assertSame($this->{$to}->id > 0 ? $rows[1]->id : 0, $row->keep_before['destination_transaction_id'] ? $rows[1]->id : 0);
        $this->assertNotSame($this->{$to}->id, $row->keep_before['destination_account_id']);
        $this->assertSame([['plaid_transaction_id' => 'OUT1', 'leg' => 'single', 'plaid_account_id' => 'pa-OUT1']], $row->keep_before['plaid_links']);
        $this->assertSame($abs['journal'], $row->absorbed_snapshot['journal']['id']);
        $this->assertCount(2, $row->absorbed_snapshot['tables']['transactions']);
        $this->assertSame('IN1', $row->absorbed_snapshot['plaid_links'][0]['plaid_transaction_id']);
        $this->assertSame($journal->updated_at->getTimestamp(), $row->keep_after_updated_at->getTimestamp());
        $this->assertSame($journal->transaction_type_id, $row->keep_after['transaction_type_id']);
        // the response carries the merged group with both links
        $links = $response->json('data.transaction_group.data.attributes.transactions.0.plaid_links');
        $this->assertEqualsCanonicalizing(['OUT1', 'IN1'], array_column($links, 'plaid_transaction_id'));
    }

    public function testTheAbsorbedAccountBecomesTheDestinationOfTheKeptJournal(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->merge($keep, $abs)->assertOk()->assertJsonPath('data.type', 'transfer');
        $this->assertSame('Transfer', TransactionJournal::with('transactionType')->find($keep['journal'])->transactionType->type);
    }

    public function testResponseGroupIsTheSameAsGetTransaction(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $response     = $this->merge($keep, $abs)->assertOk();
        $get          = $this->getJson(route('api.v1.transactions.show', ['transactionGroup' => $keep['group']]))->assertOk();
        $this->assertEquals($get->json(), $response->json('data.transaction_group'));
    }

    /** Postgres: the Plaid advisory locks of BOTH journals are taken, sorted, before any row is touched (like every link writer). */
    public function testAdvisoryLocksAreTakenInSortedOrder(): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('advisory locks are Postgres only');
        }
        [$keep, $abs] = $this->pair($this->assetB, $this->assetA, 'ZZ-OUT', 'AA-IN');
        $locks        = [];
        $firstWrite   = null;
        DB::listen(static function ($q) use (&$locks, &$firstWrite): void {
            if (str_contains($q->sql, 'pg_advisory_xact_lock')) {
                $locks[] = $q->bindings[0];
            }
            if (null === $firstWrite && preg_match('/^(update|insert|delete)/i', $q->sql)) {
                $firstWrite = count($locks);
            }
        });
        $this->merge($keep, $abs)->assertOk();
        $gid = $this->user->user_group_id;
        $this->assertSame([$gid.':AA-IN', $gid.':ZZ-OUT'], $locks);
        $this->assertSame(2, $firstWrite, 'both locks are held before the first write');
    }

    /** The database itself refuses a second LIVE merge of one pair, and allows it again once the first is unmerged. */
    public function testDatabaseAllowsOnlyOneLiveMergePerPair(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->merge($keep, $abs)->assertOk();
        $row = (array) DB::table('pair_merges')->first();
        unset($row['id']);
        try {
            DB::transaction(static fn () => DB::table('pair_merges')->insert($row));
            $this->fail('a second live merge row was accepted');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, PairMerge::count());
        DB::table('pair_merges')->update(['unmerged_at' => now()]);
        DB::table('pair_merges')->insert($row);
        $this->assertSame(2, PairMerge::count());
    }

    // ---------------------------------------------------------------- idempotence (5.3)

    public function testRetryReturnsTheSamePairMergeIdAndWritesNothing(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $first        = $this->merge($keep, $abs)->assertOk();
        $after        = $this->dump();
        $second       = $this->merge($keep, $abs);
        $second->assertOk();
        $this->assertSame($first->json('data.pair_merge_id'), $second->json('data.pair_merge_id'));
        $this->assertSame(1, PairMerge::count());
        $this->assertDumpsEqual($after, $this->dump());
        // whatever versions the retry sends
        $third = $this->merge($keep, $abs, ['keep_updated_at' => '2001-01-01T00:00:00+00:00']);
        $this->assertSame($first->json('data.pair_merge_id'), $third->json('data.pair_merge_id'));
    }

    public function testAbsorbedAlreadyMergedIntoAnotherKeepIsNotSingle(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $other        = $this->single(['type' => 'withdrawal', 'account' => $this->assetA, 'plaid' => 'OUT2']);
        $this->merge($keep, $abs)->assertOk();
        $before = $this->dump();
        $this->merge($other, $abs)->assertStatus(409)->assertJsonPath('reason', 'not_single');
        $this->assertDumpsEqual($before, $this->dump());
    }

    // ---------------------------------------------------------------- 2 and C6: stale

    public function testStaleKeepOrAbsorbedIs409AndWritesNothing(): void
    {
        foreach (['keep_updated_at', 'absorb_updated_at'] as $field) {
            [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT-'.$field, 'IN-'.$field);
            $before       = $this->dump();
            $this->merge($keep, $abs, [$field => '2001-01-01T00:00:00+00:00'])->assertStatus(409)->assertJsonPath('reason', 'stale');
            $this->assertDumpsEqual($before, $this->dump(), $field);
            $this->assertSame(0, PairMerge::count());
        }
    }

    /** C6: the connector read keep, then someone edited it. Every kind of edit must make the merge answer stale. */
    #[DataProvider('editsBetweenReadAndMerge')]
    public function testEditBetweenReadAndMergeIsStale(array $edit): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [$edit]])->assertOk();
        $before = $this->dump();
        $this->merge($keep, $abs)->assertStatus(409)->assertJsonPath('reason', 'stale');
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertSame(0, PairMerge::count());
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function editsBetweenReadAndMerge(): array
    {
        return [
            'description' => [['description' => 'renamed by the user']],
            'category'    => [['category_name' => 'Groceries']],
            'tags'        => [['tags' => ['vacation']]],
            'notes'       => [['notes' => 'a note']],
        ];
    }

    /** The same edit on the ABSORBED journal also blocks the merge. */
    public function testEditOfTheAbsorbedJournalIsStaleToo(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $abs['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['tags' => ['x']]]])->assertOk();
        $this->merge($keep, $abs)->assertStatus(409)->assertJsonPath('reason', 'stale');
    }

    /** After the connector re-reads, the merge goes through. */
    public function testMergeSucceedsWithTheFreshVersionAfterAnEdit(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['description' => 'edited']]])->assertOk();
        $this->merge($this->ref($keep['group']), $abs)->assertOk();
        $this->assertSame('edited', TransactionJournal::find($keep['journal'])->description);
    }

    // ---------------------------------------------------------------- 3: 409 reasons

    public function testSplitJournalIsNotSingle(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'group_title' => 'split', 'transactions' => [
            ['transaction_journal_id' => (string) $keep['journal']],
            ['type' => 'withdrawal', 'date' => '2026-10-01', 'amount' => '1', 'description' => 'second split', 'source_id' => (string) $this->assetA->id, 'destination_name' => 'x'],
        ]])->assertOk();
        $before = $this->dump();
        $this->merge($this->ref($keep['group']), $abs)->assertStatus(409)->assertJsonPath('reason', 'not_single');
        $this->assertDumpsEqual($before, $this->dump());
    }

    public function testJournalWithoutALinkIsNotSingle(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        DB::table('plaid_transaction_links')->where('plaid_transaction_id', 'OUT1')->delete();
        $this->merge($keep, $abs)->assertStatus(409)->assertJsonPath('reason', 'not_single');
    }

    public function testJournalWithTwoLinksIsNotSingle(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        DB::table('plaid_transaction_links')->insert(['user_group_id' => $this->user->user_group_id, 'plaid_transaction_id' => 'EXTRA', 'transaction_journal_id' => $abs['journal'], 'leg' => 'single']);
        $this->merge($keep, $abs)->assertStatus(409)->assertJsonPath('reason', 'not_single');
    }

    public function testLinkWithLegSourceIsNotSingle(): void
    {
        $keep = $this->single(['type' => 'withdrawal', 'account' => $this->assetA, 'plaid' => 'OUT1', 'leg' => 'source']);
        $abs  = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'IN1']);
        $this->merge($keep, $abs)->assertStatus(409)->assertJsonPath('reason', 'not_single');
    }

    public function testAlreadyPairedJournalIsNotSingle(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $third        = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'IN3']);
        $this->merge($keep, $abs)->assertOk();
        $this->merge($this->ref($keep['group']), $third)->assertStatus(409)->assertJsonPath('reason', 'not_single');
    }

    #[DataProvider('stateThatWouldBeLost')]
    public function testRefusesStateTheMergeWouldLose(string $reason, string $on): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $target       = 'keep' === $on ? $keep : $abs;
        $j            = $target['journal'];
        match ($reason) {
            'reconciled'    => DB::table('transactions')->where('transaction_journal_id', $j)->update(['reconciled' => true]),
            'attachments'   => DB::table('attachments')->insert(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'attachable_id' => $j, 'attachable_type' => TransactionJournal::class, 'md5' => 'x', 'filename' => 'f.txt', 'mime' => 'text/plain', 'size' => 1, 'uploaded' => true]),
            'piggy_bank'    => $this->piggyEvent($j),
            'journal_links' => DB::table('journal_links')->insert(['link_type_id' => 1, 'source_id' => $j, 'destination_id' => 'keep' === $on ? $abs['journal'] : $keep['journal']]),
        };
        $before = $this->dump();
        $this->merge($keep, $abs)->assertStatus(409)->assertJsonPath('reason', $reason);
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertSame(0, PairMerge::count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function stateThatWouldBeLost(): array
    {
        $out = [];
        foreach (['reconciled', 'attachments', 'piggy_bank', 'journal_links'] as $reason) {
            foreach (['keep', 'absorbed'] as $on) {
                $out[$reason.' on '.$on] = [$reason, $on];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- 3: 422 reasons

    public function testSameAccountIs422(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetA);
        $this->assertRefused(422, 'same_account', $keep, $abs);
    }

    public function testAmountMismatchIs422(): void
    {
        $keep = $this->single(['type' => 'withdrawal', 'account' => $this->assetA, 'plaid' => 'OUT1', 'amount' => '25.00']);
        $abs  = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'IN1', 'amount' => '25.01']);
        $this->assertRefused(422, 'amount_mismatch', $keep, $abs);
    }

    public function testCurrencyMismatchIs422(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $other        = DB::table('transaction_currencies')->where('id', '!=', DB::table('transactions')->where('transaction_journal_id', $abs['journal'])->value('transaction_currency_id'))->value('id');
        DB::table('transactions')->where('transaction_journal_id', $abs['journal'])->update(['transaction_currency_id' => $other]);
        $this->assertRefused(422, 'currency_mismatch', $keep, $abs);
    }

    public function testForeignAmountIs422AsCurrencyMismatch(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $other        = DB::table('transaction_currencies')->where('id', '!=', DB::table('transactions')->where('transaction_journal_id', $abs['journal'])->value('transaction_currency_id'))->value('id');
        DB::table('transactions')->where('transaction_journal_id', $keep['journal'])->where('amount', '<', 0)->update(['foreign_amount' => '-30', 'foreign_currency_id' => $other]);
        $this->assertRefused(422, 'currency_mismatch', $keep, $abs);
    }

    public function testTwoWithdrawalsAreNotOppositeDirections(): void
    {
        $keep = $this->single(['type' => 'withdrawal', 'account' => $this->assetA, 'plaid' => 'OUT1']);
        $abs  = $this->single(['type' => 'withdrawal', 'account' => $this->assetB, 'plaid' => 'OUT2']);
        $this->assertRefused(422, 'direction', $keep, $abs);
    }

    public function testKeepMustBeTheMoneyOutLeg(): void
    {
        $keep = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'IN1']);
        $abs  = $this->single(['type' => 'withdrawal', 'account' => $this->assetA, 'plaid' => 'OUT1']);
        $this->assertRefused(422, 'direction', $keep, $abs);
    }

    public function testAccountsThatAreNotOwnAccountsAreRefused(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $cash         = DB::table('account_types')->where('type', 'Cash account')->value('id');
        $account      = Account::factory()->for($this->user)->create(['name' => 'wallet', 'account_type_id' => $cash]);
        DB::table('transactions')->where('transaction_journal_id', $abs['journal'])->where('amount', '>', 0)->update(['account_id' => $account->id]);
        $this->assertRefused(422, 'not_own_accounts', $keep, $abs);
    }

    public function testPairNotListedInAccountToTransactionIs422(): void
    {
        $table = config('firefly.account_to_transaction');
        unset($table['Asset account']['Asset account']);
        config(['firefly.account_to_transaction' => $table]);
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->assertRefused(422, 'type_not_possible', $keep, $abs);
    }

    public function testTypeThatIsNotATransferWithdrawalOrDepositIsRefused(): void
    {
        $table = config('firefly.account_to_transaction');
        $table['Asset account']['Asset account'] = 'Opening balance';
        config(['firefly.account_to_transaction' => $table]);
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->assertRefused(422, 'type_not_possible', $keep, $abs);
    }

    public function testRequestValidation(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->merge($keep, $abs, ['keep_group_id' => 'abc'])->assertStatus(422);
        $this->merge($keep, $abs, ['absorb_group_id' => (string) $keep['group']])->assertStatus(422);
        $this->merge($keep, $abs, ['keep_updated_at' => 'not a date'])->assertStatus(422);
        $this->postJson(route('api.v1.plaid-links.pair.store'), [])->assertStatus(422);
        $this->assertSame(0, PairMerge::count());
    }

    public function testMissingGroupIs404(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $before       = $this->dump();
        $this->merge($keep, $abs, ['absorb_group_id' => '999999'])->assertStatus(404);
        $this->merge($keep, $abs, ['keep_group_id' => '999999'])->assertStatus(404);
        $this->assertDumpsEqual($before, $this->dump());
    }

    public function testDeletedGroupIs404(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->deleteJson(route('api.v1.transactions.delete', ['transactionGroup' => $abs['group']]))->assertNoContent();
        $this->merge($keep, $abs)->assertStatus(404);
    }

    // ---------------------------------------------------------------- 4: atomicity

    /** @return array<string, array{0: string}> */
    public static function injectionPoints(): array
    {
        return [
            'after the keep rows and the link move'  => ['update "plaid_transaction_links" set "transaction_journal_id"'],
            'after the soft delete of the absorbed'  => ['update "transaction_journals" set "deleted_at"'],
            'after the absorbed group soft delete'   => ['update "transaction_groups" set "deleted_at"'],
            'after the pair_merges row'              => ['insert into "pair_merges"'],
        ];
    }

    #[DataProvider('injectionPoints')]
    public function testAnExceptionAnywhereRollsEverythingBack(string $sqlPrefix): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['tags' => ['t1'], 'notes' => 'keep note'], ['tags' => ['t2'], 'notes' => 'abs note', 'category_name' => 'Cat']);
        $before       = $this->dump();
        $fired        = false;
        DB::listen(static function ($query) use ($sqlPrefix, &$fired): void {
            if (str_starts_with($query->sql, $sqlPrefix)) {
                $fired = true;

                throw new \RuntimeException('injected failure');
            }
        });
        $response = $this->merge($keep, $abs);
        $this->assertTrue($fired, 'the injection point was never reached: '.$sqlPrefix);
        $response->assertStatus(500);
        $this->assertDumpsEqual($before, $this->dump(), $sqlPrefix);
        $this->assertSame(0, PairMerge::count());
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
        $this->assertSame(['IN1' => 'single'], $this->linkLegs($abs['journal']));
        // and the merge still works afterwards (nothing was left half way, no stuck lock)
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
    }

    // ---------------------------------------------------------------- 6: user fields

    public function testUserFieldsAreMerged(): void
    {
        [$keep, $abs] = $this->pair(
            $this->assetA,
            $this->assetB,
            'OUT1',
            'IN1',
            ['tags' => ['shared', 'only-keep'], 'notes' => 'keep note', 'description' => 'Keep description'],
            ['tags' => ['shared', 'only-abs'], 'notes' => 'absorbed note', 'description' => 'Absorbed description', 'category_name' => 'Abs category'],
        );
        $this->merge($keep, $abs)->assertOk();
        $tags = DB::table('tag_transaction_journal as p')->join('tags', 'tags.id', '=', 'p.tag_id')->where('p.transaction_journal_id', $keep['journal'])->pluck('tags.tag')->all();
        $this->assertEqualsCanonicalizing(['shared', 'only-keep', 'only-abs'], $tags);
        $note = DB::table('notes')->where('noteable_id', $keep['journal'])->whereNull('deleted_at')->value('text');
        $this->assertSame("keep note\n\n--- merged from 2026-10-01 Absorbed description ---\nabsorbed note", $note);
        $this->assertSame('Keep description', TransactionJournal::find($keep['journal'])->description);
        // keep had no category: absorbed's
        $this->assertSame('Abs category', DB::table('category_transaction_journal as p')->join('categories', 'categories.id', '=', 'p.category_id')->where('p.transaction_journal_id', $keep['journal'])->value('categories.name'));
    }

    public function testKeepCategoryBudgetAndBillWinWhenSet(): void
    {
        $this->budgets('Keep budget', 'Abs budget');
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['category_name' => 'Keep cat', 'budget_name' => 'Keep budget'], ['category_name' => 'Abs cat', 'budget_name' => 'Abs budget']);
        $this->merge($keep, $abs)->assertOk();
        $cats = DB::table('category_transaction_journal as p')->join('categories', 'categories.id', '=', 'p.category_id')->where('p.transaction_journal_id', $keep['journal'])->pluck('categories.name')->all();
        $this->assertSame(['Keep cat'], $cats);
        $budgets = DB::table('budget_transaction_journal as p')->join('budgets', 'budgets.id', '=', 'p.budget_id')->where('p.transaction_journal_id', $keep['journal'])->pluck('budgets.name')->all();
        $this->assertSame(['Keep budget'], $budgets);
    }

    public function testNullFieldsOfKeepTakeTheAbsorbedBudgetAndBill(): void
    {
        $this->budgets('Abs budget');
        $billId       = DB::table('bills')->insertGetId(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Rent', 'amount_min' => 1, 'amount_max' => 2, 'date' => '2026-01-01', 'repeat_freq' => 'monthly', 'skip' => 0, 'match' => 'rent', 'automatch' => false, 'active' => true, 'transaction_currency_id' => DB::table('transaction_currencies')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1');
        // Firefly only stores a budget on a withdrawal, so a deposit never has one; the rows can still exist (imports, old data).
        DB::table('budget_transaction_journal')->insert(['budget_id' => DB::table('budgets')->value('id'), 'transaction_journal_id' => $abs['journal']]);
        DB::table('transaction_journals')->where('id', $abs['journal'])->update(['bill_id' => $billId]);
        $abs          = $this->ref($abs['group']);
        $this->merge($keep, $abs)->assertOk();
        $this->assertSame($billId, (int) DB::table('transaction_journals')->where('id', $keep['journal'])->value('bill_id'));
        $this->assertSame(1, DB::table('budget_transaction_journal')->where('transaction_journal_id', $keep['journal'])->count());
    }

    public function testIdenticalOrEmptyAbsorbedNotesAreNotAppended(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['notes' => 'same'], ['notes' => 'same']);
        $this->merge($keep, $abs)->assertOk();
        $this->assertSame('same', DB::table('notes')->where('noteable_id', $keep['journal'])->whereNull('deleted_at')->value('text'));
        [$keep2, $abs2] = $this->pair($this->assetA, $this->assetB, 'OUT2', 'IN2', ['notes' => 'only keep'], []);
        $this->merge($keep2, $abs2)->assertOk();
        $this->assertSame('only keep', DB::table('notes')->where('noteable_id', $keep2['journal'])->whereNull('deleted_at')->value('text'));
    }

    // ---------------------------------------------------------------- events after commit

    public function testEventsAreFiredAfterTheTransactionHasCommitted(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $base         = DB::transactionLevel();
        $levels       = [];
        Event::listen(UpdatedSingleTransactionGroup::class, static function () use (&$levels): void {
            $levels['updated'] = DB::transactionLevel();
        });
        Event::listen(DestroyedSingleTransactionGroup::class, static function () use (&$levels): void {
            $levels['destroyed'] = DB::transactionLevel();
        });
        $this->merge($keep, $abs)->assertOk();
        $this->assertSame(['updated' => $base, 'destroyed' => $base], $levels);
    }

    public function testNoEventsWhenTheMergeIsRefusedOrRetried(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->merge($keep, $abs)->assertOk();
        Event::fake([UpdatedSingleTransactionGroup::class, DestroyedSingleTransactionGroup::class]);
        $this->merge($keep, $abs)->assertOk();
        $this->merge($keep, $abs, ['absorb_group_id' => '999999'])->assertStatus(404);
        Event::assertNotDispatched(UpdatedSingleTransactionGroup::class);
        Event::assertNotDispatched(DestroyedSingleTransactionGroup::class);
    }

    // ---------------------------------------------------------------- 8: isolation and roles

    public function testAnotherUserGroupCannotMergeOrUnmerge(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $stranger     = $this->otherUser('stranger@email.com');
        $this->actingAs($stranger, 'api');
        $before       = $this->dump();
        $this->merge($keep, $abs)->assertStatus(404);
        $this->actingAs($this->user, 'api');
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->actingAs($stranger, 'api');
        $this->unmerge($id)->assertStatus(404);
        $this->actingAs($this->user, 'api');
        $this->assertSame(1, $this->liveMerges());
        $this->assertNotEmpty($before);
    }

    public function testReadOnlyRoleCannotMergeOrUnmerge(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $reader       = User::create(['email' => 'reader@email.com', 'password' => 'password', 'user_group_id' => $this->user->user_group_id]);
        \FireflyIII\Models\GroupMembership::create(['user_id' => $reader->id, 'user_group_id' => $this->user->user_group_id, 'user_role_id' => \FireflyIII\Models\UserRole::where('title', 'ro')->value('id')]);
        $this->actingAs($reader, 'api');
        $this->merge($keep, $abs)->assertStatus(401);
        $this->assertSame(0, PairMerge::count());
        $this->actingAs($this->user, 'api');
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->actingAs($reader, 'api');
        $this->unmerge($id)->assertStatus(401);
        $this->assertSame(1, $this->liveMerges());
    }

    public function testUnauthenticatedIs401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson(route('api.v1.plaid-links.pair.store'), [])->assertStatus(401);
    }

    // ---------------------------------------------------------------- 7: unmerge

    /** The guarantee of section 6: merge then unmerge leaves every touched table as it was (updated_at aside). */
    public function testMergeThenUnmergeRestoresEveryTouchedTable(): void
    {
        $this->budgets('Abs budget');
        [$keep, $abs] = $this->pair(
            $this->assetA,
            $this->assetB,
            'OUT1',
            'IN1',
            ['tags' => ['k1'], 'notes' => 'keep note', 'category_name' => 'Keep cat'],
            ['tags' => ['k2', 'k1'], 'notes' => 'abs note'],
        );
        DB::table('budget_transaction_journal')->insert(['budget_id' => DB::table('budgets')->value('id'), 'transaction_journal_id' => $abs['journal']]);
        DB::table('journal_meta')->insert(['transaction_journal_id' => $abs['journal'], 'name' => 'internal_reference', 'data' => json_encode('REF'), 'hash' => hash('sha256', 'REF'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('locations')->insert(['locatable_id' => $abs['journal'], 'locatable_type' => TransactionJournal::class, 'latitude' => 1.5, 'longitude' => 2.5, 'zoom_level' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $abs    = $this->ref($abs['group']);
        $before = $this->dump();
        $id     = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->assertNotEquals($before['transaction_journals'], $this->dump()['transaction_journals']);
        $response = $this->unmerge($id);
        $response->assertOk();
        $response->assertJsonPath('data.pair_merge_id', $id);
        $this->assertNotNull($response->json('data.unmerged_at'));
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertNotNull(PairMerge::find($id)->unmerged_at);
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
        $this->assertSame(['IN1' => 'single'], $this->linkLegs($abs['journal']));
        // the pair can be merged again after an unmerge (the unique index only covers live merges)
        $this->merge($this->ref($keep['group']), $this->ref($abs['group']))->assertOk();
        $this->assertSame(2, PairMerge::count());
        $this->assertSame(1, $this->liveMerges());
    }

    public function testUnmergeTwiceIsIdempotent(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $first        = $this->unmerge($id)->assertOk();
        $after        = $this->dump();
        $second       = $this->unmerge($id)->assertOk();
        $this->assertSame($first->json('data.pair_merge_id'), $second->json('data.pair_merge_id'));
        $this->assertSame($first->json('data.unmerged_at'), $second->json('data.unmerged_at'));
        $this->assertDumpsEqual($after, $this->dump());
    }

    public function testUnmergeOfAnUnknownIdIs404(): void
    {
        $this->unmerge(999999)->assertStatus(404);
    }

    /** C7: edits made after the merge survive the unmerge; only merge-changed fields are restored. */
    public function testEditAfterMergeSurvivesUnmerge(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['tags' => ['k1']], ['tags' => ['k2']]);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['description' => 'edited after merge', 'tags' => ['k1', 'k2', 'mine']]]])->assertOk();
        $this->assertSame('edited after merge', TransactionJournal::find($keep['journal'])->description);
        $this->unmerge($id)->assertOk();
        $this->assertSame('edited after merge', TransactionJournal::find($keep['journal'])->description);
        $tags = DB::table('tag_transaction_journal as p')->join('tags', 'tags.id', '=', 'p.tag_id')->where('p.transaction_journal_id', $keep['journal'])->pluck('tags.tag')->all();
        $this->assertEqualsCanonicalizing(['k1', 'mine'], $tags);
        $this->assertSame('Withdrawal', TransactionJournal::with('transactionType')->find($keep['journal'])->transactionType->type);
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
        $this->assertSame(0, DB::table('transaction_journals')->where('id', $abs['journal'])->whereNotNull('deleted_at')->count());
    }

    /** C7: the edit survives the merge itself too (a PUT to the merged keep persists). */
    public function testPutToMergedKeepPersists(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->merge($keep, $abs)->assertOk();
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['description' => 'after', 'tags' => ['t']]]])->assertOk();
        $this->assertSame('after', TransactionJournal::find($keep['journal'])->description);
        $this->assertSame(['IN1' => 'destination', 'OUT1' => 'source'], $this->linkLegs($keep['journal']));
    }

    /** C7: a conflicting merge-changed field is a 409 diverged naming the field; force restores it anyway. */
    public function testConflictingEditIsDivergedAndForceOverrides(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', [], ['category_name' => 'From absorbed']);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['description' => 'fine', 'category_name' => 'User chose this']]])->assertOk();
        $before = $this->dump();
        $res    = $this->unmerge($id)->assertStatus(409);
        $res->assertJsonPath('reason', 'diverged');
        $this->assertSame(['category'], array_column($res->json('fields'), 'field'));
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertNull(PairMerge::find($id)->unmerged_at);

        $this->unmerge($id, true)->assertOk();
        $this->assertSame(0, DB::table('category_transaction_journal')->where('transaction_journal_id', $keep['journal'])->count());
        $this->assertSame('fine', TransactionJournal::find($keep['journal'])->description);
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
    }

    public function testEditedNotesAndTypeAreDiverged(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['notes' => 'n1'], ['notes' => 'n2']);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['notes' => 'rewritten']]])->assertOk();
        $res = $this->unmerge($id)->assertStatus(409);
        $this->assertSame(['notes'], array_column($res->json('fields'), 'field'));
    }

    public function testRemovedLinkIsDiverged(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        DB::table('plaid_transaction_links')->where('plaid_transaction_id', 'IN1')->delete();
        $res          = $this->unmerge($id)->assertStatus(409);
        $this->assertSame(['plaid_links'], array_column($res->json('fields'), 'field'));
        // forced: the absorbed id is re-created on the restored journal
        $this->unmerge($id, true)->assertOk();
        $this->assertSame(['IN1' => 'single'], $this->linkLegs($abs['journal']));
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
    }

    /** The absorbed rows were purged (Firefly's "purge deleted data"): unmerge re-creates them from the snapshot. */
    public function testUnmergeAfterTheAbsorbedRowsWerePurged(): void
    {
        $this->budgets('Bud');
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', [], ['tags' => ['k2'], 'notes' => 'abs note', 'category_name' => 'Cat']);
        DB::table('budget_transaction_journal')->insert(['budget_id' => DB::table('budgets')->value('id'), 'transaction_journal_id' => $abs['journal']]);
        DB::table('journal_meta')->insert(['transaction_journal_id' => $abs['journal'], 'name' => 'internal_reference', 'data' => json_encode('REF'), 'hash' => hash('sha256', 'REF'), 'created_at' => now(), 'updated_at' => now()]);
        $abs          = $this->ref($abs['group']);
        $before       = $this->dump();
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $j            = $abs['journal'];
        foreach (['journal_meta', 'transactions', 'tag_transaction_journal', 'category_transaction_journal', 'budget_transaction_journal'] as $table) {
            DB::table($table)->where('transaction_journal_id', $j)->delete();
        }
        DB::table('notes')->where('noteable_id', $j)->where('noteable_type', TransactionJournal::class)->delete();
        DB::table('transaction_journals')->where('id', $j)->delete();
        DB::table('transaction_groups')->where('id', $abs['group'])->delete();
        $this->assertSame(0, DB::table('transaction_journals')->where('id', $j)->count());
        $this->unmerge($id)->assertOk();
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertSame(['IN1' => 'single'], $this->linkLegs($j));
        $this->assertSame(1, TransactionGroup::where('id', $abs['group'])->count());
    }

    /** The absorbed Plaid id was freed and imported again as a different journal: 409 link_conflict, nothing changes. */
    public function testUnmergeWhenTheAbsorbedPlaidIdWasReimportedIsLinkConflict(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        DB::table('plaid_transaction_links')->where('plaid_transaction_id', 'IN1')->delete();
        $again        = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'IN1', 'description' => 'imported again']);
        $before       = $this->dump();
        foreach ([false, true] as $force) {
            $res = $this->unmerge($id, $force)->assertStatus(409);
            $res->assertJsonPath('reason', 'link_conflict');
            $res->assertJsonPath('conflicts.0.plaid_transaction_id', 'IN1');
            $res->assertJsonPath('conflicts.0.transaction_group_id', $again['group']);
            $this->assertDumpsEqual($before, $this->dump());
        }
        $this->assertNull(PairMerge::find($id)->unmerged_at);
    }

    public function testUnmergeWhenKeepWasDeletedIsRefused(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->deleteJson(route('api.v1.transactions.delete', ['transactionGroup' => $keep['group']]))->assertNoContent();
        $before       = $this->dump();
        $this->unmerge($id, true)->assertStatus(409)->assertJsonPath('reason', 'keep_missing');
        $this->assertDumpsEqual($before, $this->dump());
    }

    /** Atomicity of the unmerge: an exception half way restores nothing partially. */
    #[DataProvider('unmergeInjectionPoints')]
    public function testAnExceptionDuringUnmergeRollsBack(string $sqlPrefix): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['tags' => ['t1']], ['tags' => ['t2'], 'notes' => 'abs']);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $before       = $this->dump();
        $fired        = false;
        DB::listen(static function ($query) use ($sqlPrefix, &$fired): void {
            if (str_starts_with($query->sql, $sqlPrefix)) {
                $fired = true;

                throw new \RuntimeException('injected failure');
            }
        });
        $this->unmerge($id)->assertStatus(500);
        $this->assertTrue($fired, 'the injection point was never reached: '.$sqlPrefix);
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertNull(PairMerge::find($id)->unmerged_at);
    }

    /** @return array<string, array{0: string}> */
    public static function unmergeInjectionPoints(): array
    {
        return [
            'after the absorbed link moved back' => ['update "plaid_transaction_links" set "transaction_journal_id"'],
            'after the merge row is closed'      => ['update "pair_merges" set "unmerged_at"'],
        ];
    }

    private function piggyEvent(int $journalId): void
    {
        $piggy = DB::table('piggy_banks')->insertGetId(['name' => 'Piggy', 'target_amount' => 100, 'account_id' => $this->assetA->id, 'order' => 1, 'active' => true, 'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('piggy_bank_events')->insert(['piggy_bank_id' => $piggy, 'transaction_journal_id' => $journalId, 'date' => '2026-10-01', 'amount' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param array{group: int, journal: int, at: string} $keep
     * @param array{group: int, journal: int, at: string} $abs
     */
    private function assertRefused(int $status, string $reason, array $keep, array $abs): void
    {
        $before = $this->dump();
        $this->merge($this->ref($keep['group']), $this->ref($abs['group']))->assertStatus($status)->assertJsonPath('reason', $reason);
        $this->assertDumpsEqual($before, $this->dump(), $reason);
        $this->assertSame(0, PairMerge::count());
    }
}
