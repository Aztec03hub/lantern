<?php

/*
 * 2026_10_06_120000_create_plaid_transaction_links.php
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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plaid_transaction_links');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('plaid_transaction_links')) {
            Schema::create('plaid_transaction_links', function (Blueprint $table) {
                $table->bigInteger('user_group_id', false, true);
                $table->string('plaid_transaction_id', 255);
                $table->integer('transaction_journal_id', false, true);
                $table->enum('leg', ['single', 'source', 'destination']);
                $table->string('plaid_account_id', 255)->nullable();
                $table->timestamps();

                // the guarantee: one Plaid transaction id can only be linked once per user group.
                $table->primary(['user_group_id', 'plaid_transaction_id']);
                $table->index('transaction_journal_id');
                $table->foreign('user_group_id')->references('id')->on('user_groups')->onDelete('cascade');
                $table->foreign('transaction_journal_id')->references('id')->on('transaction_journals')->onDelete('cascade');
            });
        }
    }
};
