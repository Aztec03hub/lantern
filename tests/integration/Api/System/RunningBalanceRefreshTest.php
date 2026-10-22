<?php

/*
 * RunningBalanceRefreshTest.php
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

namespace Tests\integration\Api\System;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Override;
use Tests\integration\TestCase;

/**
 * Turning use_running_balance on refreshes every stored balance; the derived running_balance_dirty flag (U35).
 *
 * @internal
 *
 * @coversNothing
 */
final class RunningBalanceRefreshTest extends TestCase
{
    private Account $asset;
    private User $user;

    public function testPuttingTheSettingOnAfterABulkWriteRecomputesEveryBalance(): void
    {
        $this->journal('10.00', '2026-10-01');
        $this->journal('5.00', '2026-10-02');
        $this->setRunningBalance(false)->assertOk();
        $this->journal('7.50', '2026-10-03');
        $this->journal('2.25', '2026-10-04');
        $this->corrupt();

        $response = $this->setRunningBalance(true);

        $response->assertOk();
        $this->assertTrue($response->json('data.refreshed'));
        $this->assertSame(DB::table('transactions')->whereNull('deleted_at')->count(), $response->json('data.rows'));
        $this->assertBalancesAreRight();
        $this->assertSame(0, DB::table('transactions')->where('balance_dirty', true)->count());
    }

    public function testADeletedJournalDoesNotLeaveALaterBalanceStale(): void
    {
        $first = $this->journal('10.00', '2026-10-01');
        $this->journal('5.00', '2026-10-02');
        $this->journal('3.00', '2026-10-03');
        $this->setRunningBalance(false)->assertOk();
        $this->deleteJson(route('api.v1.transactions.delete', [$first]))->assertNoContent();

        $this->setRunningBalance(true)->assertOk();

        $this->assertBalancesAreRight();
    }

    public function testNothingIsRefreshedWhenTheSettingWasAlreadyOnAndNothingIsDirty(): void
    {
        $this->journal('10.00', '2026-10-01');
        $this->setRunningBalance(true)->assertOk();
        $this->corrupt();
        DB::table('transactions')->update(['balance_dirty' => false]);

        $on = $this->setRunningBalance(true);
        $on->assertOk();
        $this->assertArrayNotHasKey('refreshed', $on->json('data'));
        $this->assertSame(0, DB::table('transactions')->where('balance_after', '!=', '999.00')->count());

        $off = $this->setRunningBalance(false);
        $off->assertOk();
        $this->assertArrayNotHasKey('refreshed', $off->json('data'));
        $this->assertSame(0, DB::table('transactions')->where('balance_after', '!=', '999.00')->count());
    }

    public function testPuttingOnWhileAlreadyOnRepairsLeftoverDirtyRows(): void
    {
        $this->journal('10.00', '2026-10-01');
        $this->journal('5.00', '2026-10-02');
        $this->setRunningBalance(true)->assertOk();
        $this->corrupt();

        $response = $this->setRunningBalance(true);

        $this->assertTrue($response->json('data.refreshed'));
        $this->assertBalancesAreRight();
    }

    public function testTheFlagReadsTrueWhileRowsAreDirtyAndFalseAfterTheRefresh(): void
    {
        $show = fn () => $this->getJson(route('api.v1.configuration.show', ['configuration.running_balance_dirty']));
        $this->journal('10.00', '2026-10-01');
        DB::table('transactions')->update(['balance_dirty' => false]);
        $this->assertFalse($show()->json('data.value'));

        $this->corrupt();
        $dirty = $show();
        $dirty->assertOk();
        $this->assertSame(['data' => ['title' => 'configuration.running_balance_dirty', 'value' => true, 'editable' => false]], $dirty->json());

        $this->setRunningBalance(true)->assertOk();
        $this->assertFalse($show()->json('data.value'));
    }

    public function testTheFlagCannotBeWritten(): void
    {
        $this->putJson(route('api.v1.configuration.update', ['configuration.running_balance_dirty']), ['value' => false])->assertNotFound();
    }

    public function testAFailedRefreshLeavesTheSettingOffAndTheFlagUp(): void
    {
        $this->journal('10.00', '2026-10-01');
        $this->setRunningBalance(false)->assertOk();
        $this->corrupt();
        DB::listen(static function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'transactions')) {
                throw new \RuntimeException('boom');
            }
        });

        $this->setRunningBalance(true)->assertStatus(500);

        $this->assertFalse(AppConfiguration::getFresh('use_running_balance')?->data);
        $this->assertTrue(DB::table('transactions')->where('balance_dirty', true)->exists());
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->user  = $this->createAuthenticatedUser();
        $this->user->roles()->attach((int) DB::table('roles')->where('name', 'owner')->value('id')); // instance admin, for api-admin
        // creating the user can leave two use_running_balance rows (get-or-create has no unique key); the controller and
        // the test read the first one Postgres returns, so start from exactly one row
        DB::table('configuration')->where('name', 'use_running_balance')->delete();
        AppConfiguration::set('use_running_balance', true);
        $this->actingAs($this->user, 'api');
        $this->asset = Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create(['name' => 'Asset A', 'user_group_id' => $this->user->user_group_id]);
    }

    private function setRunningBalance(bool $value): \Illuminate\Testing\TestResponse
    {
        return $this->putJson(route('api.v1.configuration.update', ['configuration.use_running_balance']), ['value' => $value]);
    }

    private function journal(string $amount, string $date): int
    {
        $response = $this->postJson(route('api.v1.transactions.store'), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [[
            'type' => 'withdrawal', 'date' => $date, 'amount' => $amount, 'description' => 'spend '.$amount,
            'source_id' => (string) $this->asset->id, 'destination_name' => 'Some shop',
        ]]]);
        $response->assertOk();

        return (int) $response->json('data.id');
    }

    private function corrupt(): void
    {
        DB::table('transactions')->update(['balance_after' => '999.00', 'balance_before' => '999.00', 'balance_dirty' => true]);
    }

    /** Independent running sum per account, in journal date then id order, from the amounts alone. */
    private function assertBalancesAreRight(): void
    {
        $rows = DB::table('transactions')->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
            ->whereNull('transactions.deleted_at')->orderBy('transaction_journals.date')->orderBy('transaction_journals.id')->orderBy('transactions.id')
            ->get(['transactions.id', 'transactions.account_id', 'transactions.amount', 'transactions.balance_after']);
        $this->assertNotEmpty($rows);
        $sum  = [];
        foreach ($rows as $row) {
            $sum[$row->account_id] = bcadd($sum[$row->account_id] ?? '0', (string) $row->amount, 2);
            $this->assertSame(0, bccomp($sum[$row->account_id], (string) $row->balance_after, 2), 'balance_after of transaction '.$row->id);
        }
    }
}
