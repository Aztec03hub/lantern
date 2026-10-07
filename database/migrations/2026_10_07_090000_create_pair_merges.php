<?php

/*
 * 2026_10_07_090000_create_pair_merges.php
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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pair_merges');
    }

    /**
     * Run the migrations.
     *
     * One row per transfer-pair merge (docs/design/transfer-pairer.md, section 3). The application never deletes rows.
     */
    public function up(): void
    {
        if (Schema::hasTable('pair_merges')) {
            return;
        }
        Schema::create('pair_merges', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('user_group_id', false, true);
            $table->integer('keep_group_id', false, true);
            $table->integer('keep_journal_id', false, true);
            // no foreign key: a purge of the soft-deleted absorbed rows must not cascade into the audit record.
            $table->bigInteger('absorbed_group_id', false, true);
            $table->bigInteger('absorbed_journal_id', false, true);
            $table->json('keep_before');
            // what the merge left on keep, so unmerge can tell "changed by the merge" from "edited since" (design 3 stores
            // only keep_after_updated_at; the version stamp has one second resolution, the fields do not).
            $table->json('keep_after');
            $table->json('absorbed_snapshot');
            $table->timestamp('keep_after_updated_at')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamp('unmerged_at')->nullable();

            $table->index(['user_group_id', 'keep_journal_id']);
            $table->index(['user_group_id', 'absorbed_journal_id']);
            $table->foreign('user_group_id')->references('id')->on('user_groups')->onDelete('cascade');
            $table->foreign('keep_group_id')->references('id')->on('transaction_groups')->onDelete('cascade');
            $table->foreign('keep_journal_id')->references('id')->on('transaction_journals')->onDelete('cascade');
        });

        // at most one LIVE merge per pair. Postgres and SQLite both support a partial unique index.
        // MySQL has none: the fallback is the plain unique key of the design, which only constrains unmerged rows.
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX pair_merges_live_unique ON pair_merges (user_group_id, keep_journal_id, absorbed_journal_id) WHERE unmerged_at IS NULL');

            return;
        }
        Schema::table('pair_merges', function (Blueprint $table): void {
            $table->unique(['user_group_id', 'keep_journal_id', 'absorbed_journal_id', 'unmerged_at'], 'pair_merges_live_unique');
        });
    }
};
