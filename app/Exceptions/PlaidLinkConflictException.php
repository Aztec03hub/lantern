<?php

/*
 * PlaidLinkConflictException.php
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

namespace FireflyIII\Exceptions;

use Exception;

/**
 * Thrown when a Plaid transaction id is already linked to another journal.
 */
class PlaidLinkConflictException extends Exception
{
    /**
     * @param array<int, array{plaid_transaction_id: string, transaction_journal_id: int, transaction_group_id: int, leg: string}> $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('One or more Plaid transaction ids are already imported.');
    }
}
