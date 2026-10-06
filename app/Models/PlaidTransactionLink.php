<?php

/*
 * PlaidTransactionLink.php
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

namespace FireflyIII\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links one Plaid transaction id to one leg of a Firefly III transaction journal.
 * The primary key (user_group_id, plaid_transaction_id) is what refuses duplicates.
 *
 * @property int    $user_group_id
 * @property string $plaid_transaction_id
 * @property int    $transaction_journal_id
 * @property string $leg
 * @property ?string $plaid_account_id
 */
class PlaidTransactionLink extends Model
{
    public $incrementing = false;
    protected $fillable  = ['user_group_id', 'plaid_transaction_id', 'transaction_journal_id', 'leg', 'plaid_account_id'];

    public function transactionJournal(): BelongsTo
    {
        return $this->belongsTo(TransactionJournal::class);
    }

    protected function casts(): array
    {
        return [
            'user_group_id'          => 'integer',
            'transaction_journal_id' => 'integer',
        ];
    }
}
