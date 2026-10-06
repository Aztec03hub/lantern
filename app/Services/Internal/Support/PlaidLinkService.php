<?php

/*
 * PlaidLinkService.php
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

namespace FireflyIII\Services\Internal\Support;

use FireflyIII\Exceptions\PlaidLinkConflictException;
use FireflyIII\Models\PlaidTransactionLink;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the Plaid links of a journal. Always call this inside a database transaction
 * that also holds the journal write, so a conflict leaves nothing behind.
 */
class PlaidLinkService
{
    /**
     * Make the links of this journal equal to $links (the full desired set).
     * Links not in $links are removed, so replacing an id (pending to posted) is just sending the new id.
     *
     * @param array<int, array{plaid_transaction_id: string, leg: string, plaid_account_id: ?string}> $links
     *
     * @throws PlaidLinkConflictException
     */
    public function sync(TransactionJournal $journal, array $links): void
    {
        $groupId   = $journal->user_group_id;
        $wanted    = array_column($links, 'plaid_transaction_id');
        PlaidTransactionLink::where('user_group_id', $groupId)
            ->where('transaction_journal_id', $journal->id)
            ->whereNotIn('plaid_transaction_id', $wanted)
            ->delete()
        ;

        $conflicts = [];
        foreach ($links as $link) {
            $attributes = ['leg' => $link['leg'], 'plaid_account_id' => $link['plaid_account_id'] ?? null, 'transaction_journal_id' => $journal->id];
            $existing   = PlaidTransactionLink::where('user_group_id', $groupId)->where('plaid_transaction_id', $link['plaid_transaction_id'])->first();
            if (null !== $existing && $existing->transaction_journal_id !== $journal->id) {
                $conflicts[] = $this->describe($existing);

                continue;
            }
            if (null !== $existing) {
                $existing->update($attributes);

                continue;
            }

            try {
                // savepoint: on Postgres a failed insert would otherwise poison the whole transaction.
                DB::transaction(static function () use ($groupId, $link, $attributes): void {
                    PlaidTransactionLink::create(['user_group_id' => $groupId, 'plaid_transaction_id' => $link['plaid_transaction_id']] + $attributes);
                });
            } catch (UniqueConstraintViolationException) {
                // lost a race with a concurrent request that committed the same id first.
                $winner = PlaidTransactionLink::where('user_group_id', $groupId)->where('plaid_transaction_id', $link['plaid_transaction_id'])->firstOrFail();
                $conflicts[] = $this->describe($winner);
            }
        }
        if ([] !== $conflicts) {
            throw new PlaidLinkConflictException($conflicts);
        }
    }

    /**
     * @return array{plaid_transaction_id: string, transaction_journal_id: int, transaction_group_id: int, leg: string}
     */
    public function describe(PlaidTransactionLink $link): array
    {
        return [
            'plaid_transaction_id'   => $link->plaid_transaction_id,
            'transaction_journal_id' => $link->transaction_journal_id,
            'transaction_group_id'   => (int) $link->transactionJournal()->withTrashed()->value('transaction_group_id'),
            'leg'                    => $link->leg,
        ];
    }
}
