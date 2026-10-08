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

use FireflyIII\Models\Account;
use FireflyIII\Models\PairMerge;
use FireflyIII\Models\PlaidTransactionLink;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;
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

    /** Real data: a PUT left the group one second ahead of its journal; the group's stamp (what the API shows) is current. */
    public function testGroupStampASecondAheadOfItsJournalIsNotStale(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $groupAt      = TransactionJournal::find($keep['journal'])->updated_at->copy()->addSecond();
        \Illuminate\Support\Facades\DB::table('transaction_groups')->where('id', $keep['group'])->update(['updated_at' => $groupAt->toDateTimeString()]);
        $this->merge(['at' => $groupAt->toAtomString()] + $keep, $abs)->assertOk();
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

    private function piggyEvent(int $journalId): void
    {
        $piggy = DB::table('piggy_banks')->insertGetId(['name' => 'Piggy', 'target_amount' => 100, 'account_id' => $this->assetA->id, 'order' => 1, 'active' => true, 'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('piggy_bank_events')->insert(['piggy_bank_id' => $piggy, 'transaction_journal_id' => $journalId, 'date' => '2026-10-01', 'amount' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
