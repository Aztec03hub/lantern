<?php

/*
 * 2026_10_19_100100_enforce_plaid_link_leg_check.php
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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const LEGS = ['single', 'source', 'destination'];

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ('pgsql' === DB::connection()->getDriverName() && Schema::hasTable('plaid_transaction_links')) {
            DB::statement('ALTER TABLE plaid_transaction_links DROP CONSTRAINT IF EXISTS plaid_transaction_links_leg_check');
        }
    }

    /**
     * Run the migrations.
     *
     * The create migration only makes the leg CHECK on a fresh table; a table made by its first version (a plain string
     * column) has none. Add it, but stop (and change nothing) if a row already breaks it.
     */
    public function up(): void
    {
        if (!Schema::hasTable('plaid_transaction_links')) {
            return;
        }
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            // sqlite cannot add a CHECK without rebuilding the table; it is a test/dev database and a fresh one gets the CHECK from the create migration.
            return;
        }
        if ($this->present($driver)) {
            return;
        }
        $bad = DB::table('plaid_transaction_links')->whereNotIn('leg', self::LEGS)->count();
        if ($bad > 0) {
            throw new RuntimeException(sprintf('plaid_transaction_links has %d row(s) with a leg outside single/source/destination; fix them before migrating', $bad));
        }
        if ('pgsql' === $driver) {
            DB::statement("ALTER TABLE plaid_transaction_links ADD CONSTRAINT plaid_transaction_links_leg_check CHECK (leg IN ('single','source','destination'))");

            return;
        }
        DB::statement("ALTER TABLE plaid_transaction_links MODIFY leg ENUM('single','source','destination') NOT NULL");
    }

    private function present(string $driver): bool
    {
        if ('pgsql' === $driver) {
            return (int) DB::selectOne("select count(*) as c from pg_constraint where conrelid = 'plaid_transaction_links'::regclass and contype = 'c' and pg_get_constraintdef(oid) like '%leg%'")->c > 0;
        }
        $type = DB::selectOne("select column_type as t from information_schema.columns where table_schema = database() and table_name = 'plaid_transaction_links' and column_name = 'leg'");

        return null !== $type && str_starts_with(strtolower((string) $type->t), 'enum');
    }
};
