<?php

/*
 * PlaidLinkLegCheckTest.php
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

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\integration\TestCase;

/**
 * The leg CHECK on plaid_transaction_links for a table made by the first version of its create migration (plain string leg).
 */
final class PlaidLinkLegCheckTest extends TestCase
{
    private const string MIGRATION = 'database/migrations/2026_10_19_100100_enforce_plaid_link_leg_check.php';

    private function oldShape(): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('the leg CHECK migration is tested on Postgres only');
        }
        Schema::drop('plaid_transaction_links');
        Schema::create('plaid_transaction_links', static function (Blueprint $table): void {
            $table->bigInteger('user_group_id', false, true);
            $table->string('plaid_transaction_id', 255);
            $table->integer('transaction_journal_id', false, true);
            $table->string('leg', 16);
            $table->string('plaid_account_id', 255)->nullable();
            $table->timestamps();
            $table->primary(['user_group_id', 'plaid_transaction_id']);
        });
    }

    private function row(string $id, string $leg): void
    {
        DB::table('plaid_transaction_links')->insert(['user_group_id' => 1, 'plaid_transaction_id' => $id, 'transaction_journal_id' => 1, 'leg' => $leg]);
    }

    private function migrate(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function legChecks(): int
    {
        return (int) DB::selectOne("select count(*) as c from pg_constraint where conrelid = 'plaid_transaction_links'::regclass and contype = 'c' and pg_get_constraintdef(oid) like '%leg%'")->c;
    }

    public function testTheLegCheckIsAddedToAnExistingTableWithoutIt(): void
    {
        $this->oldShape();
        $this->row('P1', 'single');
        $this->row('P2', 'source');
        $this->row('bogus-ok', 'bogus');
        DB::table('plaid_transaction_links')->where('plaid_transaction_id', 'bogus-ok')->delete();
        $this->assertSame(0, $this->legChecks());
        $this->migrate();
        $this->assertSame(1, $this->legChecks());
        $this->assertSame(['P1' => 'single', 'P2' => 'source'], DB::table('plaid_transaction_links')->orderBy('plaid_transaction_id')->pluck('leg', 'plaid_transaction_id')->all());
        try {
            DB::transaction(fn () => $this->row('P3', 'bogus')); // savepoint: a failed statement aborts the surrounding test transaction
            $this->fail('the CHECK did not refuse a bogus leg');
        } catch (QueryException $e) {
            $this->assertStringContainsString('plaid_transaction_links_leg_check', $e->getMessage());
        }
        $this->migrate();
        $this->assertSame(1, $this->legChecks());
    }

    public function testTheLegMigrationRefusesWhenABadRowExists(): void
    {
        $this->oldShape();
        $this->row('P1', 'single');
        $this->row('P2', 'bogus');
        try {
            $this->migrate();
            $this->fail('the migration must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 row', $e->getMessage());
        }
        $this->assertSame(2, DB::table('plaid_transaction_links')->count());
        $this->assertSame(0, $this->legChecks());
        $this->row('P3', 'bogus');
        $this->assertSame(3, DB::table('plaid_transaction_links')->count());
    }

    public function testTheLegMigrationDoesNothingWhenTheCheckExists(): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('the leg CHECK migration is tested on Postgres only');
        }
        $this->assertSame(1, $this->legChecks());
        $this->migrate();
        $this->assertSame(1, $this->legChecks());
    }
}
