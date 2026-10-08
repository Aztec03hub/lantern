<?php

/*
 * PairTestCase.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\PairMerge;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Override;
use Tests\integration\TestCase;

/**
 * Shared set-up and helpers of the transfer pair tests (docs/core-pair-merge.md).
 *
 * @internal
 *
 * @coversNothing
 */
abstract class PairTestCase extends TestCase
{
    /** Every table a merge touches. Dumped before and after, compared without updated_at. */
    protected const array TOUCHED = [
        'transaction_groups', 'transaction_journals', 'transactions', 'journal_meta', 'notes', 'locations', 'audit_log_entries',
        'tag_transaction_journal', 'category_transaction_journal', 'budget_transaction_journal', 'journal_links',
        'plaid_transaction_links', 'tags', 'categories', 'budgets', 'bills', 'attachments', 'piggy_bank_events', 'accounts',
    ];

    protected Account $assetA;
    protected Account $assetB;
    protected Account $loanA;
    protected Account $loanB;
    protected User $user;
    /** @var array<int, array{expense: int, revenue: int}> counter accounts per user */
    private array $counter = [];

    /**
     * Create a journal with ONE Plaid link of leg single through the real API.
     *
     * @param array<string, mixed> $o type (withdrawal|deposit), account (own account), plaid, amount, date, description, extra (merged into the split)
     *
     * @return array{group: int, journal: int, at: string}
     */
    protected function single(array $o): array
    {
        $type  = $o['type'] ?? 'withdrawal';
        $split = [
            'type'        => $type,
            'date'        => $o['date'] ?? '2026-10-01',
            'amount'      => $o['amount'] ?? '25.00',
            'description' => $o['description'] ?? ($type.' '.($o['plaid'] ?? '')),
            'plaid_links' => [['plaid_transaction_id' => $o['plaid'], 'leg' => $o['leg'] ?? 'single', 'plaid_account_id' => 'pa-'.$o['plaid']]],
        ];
        if ('withdrawal' === $type) {
            $split += ['source_id' => (string) $o['account']->id, 'destination_name' => $o['counter'] ?? 'Some shop'];
        }
        if ('deposit' === $type) {
            $split += ['source_name' => $o['counter'] ?? 'Some payer', 'destination_id' => (string) $o['account']->id];
        }
        $split    = array_merge($split, $o['extra'] ?? []);
        $response = $this->postJson(route('api.v1.transactions.store'), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [$split]]);
        $response->assertOk();
        $group = (int) $response->json('data.id');

        return $this->ref($group);
    }

    /**
     * The same as single(), but with raw inserts: about 50 times faster, for the concurrency rounds that need hundreds
     * of journals. The rows are the ones the API writes (checked by every other test through single()).
     *
     * @param array<string, mixed> $o type, account, plaid, amount, description, user (default: the test user), journalAhead (journal stamp one second past its group's)
     *
     * @return array{group: int, journal: int, at: string}
     */
    protected function fastSingle(array $o): array
    {
        $type     = $o['type'] ?? 'withdrawal';
        $now      = now()->toDateTimeString();
        $currency = (int) DB::table('transaction_currencies')->where('code', 'EUR')->value('id');
        $user     = $o['user'] ?? $this->user;
        $uid      = $user->id;
        $ugid     = $user->user_group_id;
        $counter  = $this->counter[$uid] ??= $this->counterAccounts($user);
        $amount   = $o['amount'] ?? '25.00';
        $desc     = $o['description'] ?? ($type.' '.$o['plaid']);
        $group    = DB::table('transaction_groups')->insertGetId(['user_id' => $uid, 'user_group_id' => $ugid, 'title' => null, 'created_at' => $now, 'updated_at' => $now]);
        $ahead    = ($o['journalAhead'] ?? false) ? now()->addSecond()->toDateTimeString() : $now;
        $journal  = DB::table('transaction_journals')->insertGetId([
            'user_id' => $uid, 'user_group_id' => $ugid, 'transaction_group_id' => $group, 'transaction_type_id' => 'withdrawal' === $type ? 7 : 1,
            'transaction_currency_id' => $currency, 'description' => $desc, 'date' => '2026-10-01 00:00:00', 'date_tz' => 'UTC', 'order' => 0, 'tag_count' => 0,
            'encrypted' => false, 'completed' => true, 'created_at' => $now, 'updated_at' => $ahead,
        ]);
        $own      = $o['account']->id;
        $other    = 'withdrawal' === $type ? $counter['expense'] : $counter['revenue'];
        $sourceId = 'withdrawal' === $type ? $own : $other;
        $destId   = 'withdrawal' === $type ? $other : $own;
        foreach ([[$sourceId, '-'.$amount], [$destId, $amount]] as [$account, $value]) {
            DB::table('transactions')->insert(['account_id' => $account, 'transaction_journal_id' => $journal, 'amount' => $value, 'transaction_currency_id' => $currency, 'identifier' => 0, 'reconciled' => false, 'balance_dirty' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('plaid_transaction_links')->insert(['user_group_id' => $ugid, 'plaid_transaction_id' => $o['plaid'], 'transaction_journal_id' => $journal, 'leg' => 'single', 'plaid_account_id' => 'pa-'.$o['plaid'], 'created_at' => $now, 'updated_at' => $now]);

        // no API round trip here (hundreds of rows); the stamps of journal and group are identical by construction
        return ['group' => $group, 'journal' => $journal, 'at' => $this->serverVersion($group)];
    }

    /** @return array{expense: int, revenue: int} */
    private function counterAccounts(User $user): array
    {
        $make = fn (string $type, string $name): int => (int) Account::factory()->for($user)->create(['name' => $name, 'user_group_id' => $user->user_group_id, 'account_type_id' => DB::table('account_types')->where('type', $type)->value('id')])->id;

        return ['expense' => $make('Expense account', 'Shop'), 'revenue' => $make('Revenue account', 'Payer')];
    }

    /**
     * The group as a client sees it: the stamp comes from GET /api/v1/transactions/{id}, exactly what the connector
     * sends back, NOT from the database with the service's own rule (that mirror hid review R1 core-1 H1).
     *
     * @return array{group: int, journal: int, at: string}
     */
    protected function ref(int $group): array
    {
        $journal = TransactionJournal::where('transaction_group_id', $group)->orderBy('id')->firstOrFail();
        $stamp   = (string) $this->getJson(route('api.v1.transactions.show', ['transactionGroup' => $group]))->assertOk()->json('data.attributes.updated_at');

        return ['group' => $group, 'journal' => (int) $journal->id, 'at' => $stamp];
    }

    /** The stamp the SERVER holds for a group's journal (later of journal and group), for assertions about stored stamps. */
    protected function serverVersion(int $group): string
    {
        $journal = TransactionJournal::where('transaction_group_id', $group)->orderBy('id')->firstOrFail();

        return (string) \FireflyIII\Services\Internal\Pair\PairMergeService::versionOf((int) $journal->id)?->toAtomString();
    }

    /**
     * updated_at of every group and journal, keyed by table:id, for "the version never goes backwards" assertions.
     *
     * @return array<string, int>
     */
    protected function stamps(): array
    {
        $out = [];
        foreach (['transaction_groups', 'transaction_journals'] as $table) {
            foreach (DB::table($table)->get(['id', 'updated_at']) as $row) {
                $out[$table.':'.$row->id] = \Carbon\Carbon::parse($row->updated_at)->getTimestamp();
            }
        }

        return $out;
    }

    /** @param array<string, int> $before */
    protected function assertStampsNotOlder(array $before, string $message = ''): void
    {
        $now = $this->stamps();
        foreach ($before as $key => $stamp) {
            $this->assertGreaterThanOrEqual($stamp, $now[$key] ?? $stamp, trim($message.' '.$key.' went back in time'));
        }
    }

    /** Two singles that form a transfer: money out of $from, money into $to. */
    protected function pair(Account $from, Account $to, string $out = 'OUT1', string $in = 'IN1', array $outExtra = [], array $inExtra = []): array
    {
        return [
            $this->single(['type' => 'withdrawal', 'account' => $from, 'plaid' => $out, 'extra' => $outExtra]),
            $this->single(['type' => 'deposit', 'account' => $to, 'plaid' => $in, 'extra' => $inExtra]),
        ];
    }

    /**
     * @param array{group: int, journal: int, at: string} $keep
     * @param array{group: int, journal: int, at: string} $absorb
     * @param array<string, mixed>                        $override
     */
    protected function merge(array $keep, array $absorb, array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('api.v1.plaid-links.pair.store'), $override + [
            'keep_group_id'     => (string) $keep['group'],
            'absorb_group_id'   => (string) $absorb['group'],
            'keep_updated_at'   => $keep['at'],
            'absorb_updated_at' => $absorb['at'],
            'evidence'          => ['layer' => 2, 'gap_days' => 1, 'rule_version' => 1],
        ]);
    }

    protected function unmerge(int|string $id, bool $force = false): \Illuminate\Testing\TestResponse
    {
        return $this->deleteJson(route('api.v1.plaid-links.pair.destroy', ['pairMerge' => $id]).($force ? '?force=true' : ''));
    }

    /**
     * Content of every touched table, without updated_at, in a stable order.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function dump(): array
    {
        $out = [];
        foreach (self::TOUCHED as $table) {
            $query = DB::table($table);
            if ('plaid_transaction_links' === $table) {
                $query->orderBy('user_group_id')->orderBy('plaid_transaction_id');
            }
            if ('plaid_transaction_links' !== $table) {
                $query->orderBy('id');
            }
            $out[$table] = $query->get()->map(static function ($row): array {
                $row = (array) $row;
                unset($row['updated_at']);

                return $row;
            })->all();
        }

        return $out;
    }

    /** Compare two dumps and name the first table that differs (a readable failure). */
    protected function assertDumpsEqual(array $expected, array $actual, string $message = ''): void
    {
        // strict and type-exact: both sides go through json so "1", 1 and true are different, only key order is free
        $norm = static fn (array $rows): array => json_decode((string) json_encode($rows), true);
        foreach ($expected as $table => $rows) {
            $this->assertSame($norm($rows), $norm($actual[$table]), trim($message.' table '.$table));
        }
    }

    /**
     * @param array{group: int, journal: int, at: string} $keep
     * @param array{group: int, journal: int, at: string} $abs
     */
    protected function assertRefused(int $status, string $reason, array $keep, array $abs): void
    {
        $before = $this->dump();
        $this->merge($this->ref($keep['group']), $this->ref($abs['group']))->assertStatus($status)->assertJsonPath('reason', $reason);
        $this->assertDumpsEqual($before, $this->dump(), $reason);
        $this->assertSame(0, PairMerge::count());
    }

    protected function budgets(string ...$names): void
    {
        foreach ($names as $name) {
            \FireflyIII\Models\Budget::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => $name, 'active' => true, 'order' => 1]);
        }
    }

    protected function liveMerges(): int
    {
        return DB::table('pair_merges')->whereNull('unmerged_at')->count();
    }

    protected function linkLegs(int $journalId): array
    {
        return DB::table('plaid_transaction_links')->where('transaction_journal_id', $journalId)->orderBy('plaid_transaction_id')->pluck('leg', 'plaid_transaction_id')->all();
    }

    protected function otherUser(string $email, string $role = 'owner'): User
    {
        $group = \FireflyIII\Models\UserGroup::create(['title' => $email]);
        $user  = User::create(['email' => $email, 'password' => 'password', 'user_group_id' => $group->id]);
        \FireflyIII\Models\GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => \FireflyIII\Models\UserRole::where('title', $role)->value('id')]);

        return $user;
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->user   = $this->createAuthenticatedUser();
        $this->actingAs($this->user, 'api');
        $this->assetA = Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create(['name' => 'Asset A', 'user_group_id' => $this->user->user_group_id]);
        $this->assetB = Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create(['name' => 'Asset B', 'user_group_id' => $this->user->user_group_id]);
        $this->loanA  = Account::factory()->for($this->user)->withType(AccountTypeEnum::LOAN)->create(['name' => 'Loan A', 'user_group_id' => $this->user->user_group_id]);
        $this->loanB  = Account::factory()->for($this->user)->withType(AccountTypeEnum::LOAN)->create(['name' => 'Loan B', 'user_group_id' => $this->user->user_group_id]);
    }
}
