<?php

/*
 * PairRefusedException.php
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
 * A transfer-pair merge or unmerge was refused. Thrown inside the database transaction, so nothing is written.
 */
class PairRefusedException extends Exception
{
    /**
     * @param array<string, mixed> $extra additional keys of the JSON body (e.g. the differing fields of a `diverged`)
     */
    public function __construct(public readonly int $status, public readonly string $reason, public readonly array $extra = [])
    {
        parent::__construct(sprintf('Pair refused: %s', $reason));
    }
}
