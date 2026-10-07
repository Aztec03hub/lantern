<?php

/*
 * PairMerge.php
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

/**
 * Audit record and undo information of one transfer-pair merge.
 *
 * @property int                 $id
 * @property int                 $user_group_id
 * @property int                 $keep_group_id
 * @property int                 $keep_journal_id
 * @property int                 $absorbed_group_id
 * @property int                 $absorbed_journal_id
 * @property array<string,mixed> $keep_before
 * @property array<string,mixed> $keep_after
 * @property array<string,mixed> $absorbed_snapshot
 * @property null|\Carbon\Carbon $keep_after_updated_at
 * @property null|array          $evidence
 * @property null|\Carbon\Carbon $merged_at
 * @property null|\Carbon\Carbon $unmerged_at
 */
class PairMerge extends Model
{
    public $timestamps  = false;
    protected $fillable = [
        'user_group_id', 'keep_group_id', 'keep_journal_id', 'absorbed_group_id', 'absorbed_journal_id',
        'keep_before', 'keep_after', 'absorbed_snapshot', 'keep_after_updated_at', 'evidence', 'merged_at', 'unmerged_at',
    ];

    protected function casts(): array
    {
        return [
            'user_group_id'         => 'integer',
            'keep_group_id'         => 'integer',
            'keep_journal_id'       => 'integer',
            'absorbed_group_id'     => 'integer',
            'absorbed_journal_id'   => 'integer',
            'keep_before'           => 'array',
            'keep_after'            => 'array',
            'absorbed_snapshot'     => 'array',
            'evidence'              => 'array',
            'keep_after_updated_at' => 'datetime',
            'merged_at'             => 'datetime',
            'unmerged_at'           => 'datetime',
        ];
    }
}
