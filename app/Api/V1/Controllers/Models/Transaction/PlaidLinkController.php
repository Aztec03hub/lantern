<?php

/*
 * PlaidLinkController.php
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

namespace FireflyIII\Api\V1\Controllers\Models\Transaction;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\PlaidTransactionLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Override;

/**
 * Resolves Plaid transaction ids to the Firefly III journals they are linked to.
 */
final class PlaidLinkController extends Controller
{
    #[Override]
    protected array $acceptedRoles = [UserRoleEnum::READ_ONLY];

    /**
     * GET /api/v1/plaid-links?plaid_transaction_id[]=a&plaid_transaction_id[]=b
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'plaid_transaction_id'   => ['required', 'array', 'min:1', 'max:500'],
            'plaid_transaction_id.*' => ['required', 'string', 'max:255'],
        ]);
        $userGroup = $this->validateUserGroup($request);
        $links     = PlaidTransactionLink::query()
            ->where('plaid_transaction_links.user_group_id', $userGroup->id)
            ->whereIn('plaid_transaction_id', $request->input('plaid_transaction_id'))
            ->join('transaction_journals', 'transaction_journals.id', '=', 'plaid_transaction_links.transaction_journal_id')
            ->get(['plaid_transaction_links.*', 'transaction_journals.transaction_group_id'])
        ;

        return response()->json(['data' => $links->map(static fn (PlaidTransactionLink $link): array => [
            'plaid_transaction_id'   => $link->plaid_transaction_id,
            'transaction_journal_id' => (string) $link->transaction_journal_id,
            'transaction_group_id'   => (string) $link->getAttribute('transaction_group_id'),
            'leg'                    => $link->leg,
            'plaid_account_id'       => $link->plaid_account_id,
        ])->values()])->header('Content-Type', self::JSON_CONTENT_TYPE);
    }
}
