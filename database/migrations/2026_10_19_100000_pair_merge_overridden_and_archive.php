<?php

/*
 * 2026_10_19_100000_pair_merge_overridden_and_archive.php
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
    private const COPIED = [
        'user_group_id', 'keep_group_id', 'keep_journal_id', 'absorbed_group_id', 'absorbed_journal_id', 'keep_before', 'keep_after',
        'absorbed_snapshot', 'keep_after_updated_at', 'evidence', 'merged_at', 'unmerged_at',
    ];

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pair_merge_archives');
        if (Schema::hasColumn('pair_merges', 'overridden')) {
            Schema::table('pair_merges', function (Blueprint $table): void {
                $table->dropColumn('overridden');
            });
        }
    }

    /**
     * Run the migrations. Additive and safe to run twice; the backfill only inserts, it never writes to pair_merges.
     *
     * overridden: what a forced unmerge overwrote (null = nothing).
     * pair_merge_archives: a copy of each pair_merges row WITHOUT the keep foreign keys, so a hard delete of keep (which
     * cascades into pair_merges, kept on purpose by Phil, LAN-5) still leaves the undo snapshot behind.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('pair_merges', 'overridden')) {
            Schema::table('pair_merges', function (Blueprint $table): void {
                $table->json('overridden')->nullable();
            });
        }
        if (!Schema::hasTable('pair_merge_archives')) {
            Schema::create('pair_merge_archives', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->bigInteger('pair_merge_id', false, true)->unique();
                $table->bigInteger('user_group_id', false, true);
                $table->bigInteger('keep_group_id', false, true);
                $table->bigInteger('keep_journal_id', false, true);
                $table->bigInteger('absorbed_group_id', false, true);
                $table->bigInteger('absorbed_journal_id', false, true);
                $table->json('keep_before');
                $table->json('keep_after');
                $table->json('absorbed_snapshot');
                $table->timestamp('keep_after_updated_at')->nullable();
                $table->json('evidence')->nullable();
                $table->timestamp('merged_at')->nullable();
                $table->timestamp('unmerged_at')->nullable();
                $table->json('overridden')->nullable();
                $table->timestamp('archived_at')->nullable();

                // deleting a whole user group is a deliberate wipe; nothing else may remove an archive row.
                $table->foreign('user_group_id')->references('id')->on('user_groups')->onDelete('cascade');
            });
        }
        $archived = DB::table('pair_merge_archives')->select('pair_merge_id');
        $source   = DB::table('pair_merges')->whereNotIn('id', $archived)
            ->select(array_merge(['id'], self::COPIED, ['overridden']))->addSelect(DB::raw('CURRENT_TIMESTAMP'))
        ;
        DB::table('pair_merge_archives')->insertUsing(array_merge(['pair_merge_id'], self::COPIED, ['overridden', 'archived_at']), $source);
    }
};
