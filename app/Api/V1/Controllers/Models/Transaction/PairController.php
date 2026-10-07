<?php

/*
 * PairController.php
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
use FireflyIII\Exceptions\PairRefusedException;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\PairMerge;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Services\Internal\Pair\PairMergeService;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\Transformers\TransactionGroupTransformer;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use League\Fractal\Resource\Item;
use Override;

/**
 * Merges the two sides of an own-account transfer into one journal with two Plaid links, and undoes that.
 * Contract: docs/core-pair-merge.md.
 */
final class PairController extends Controller
{
    #[Override]
    protected array $acceptedRoles = [UserRoleEnum::MANAGE_TRANSACTIONS];

    /**
     * DELETE /api/v1/plaid-links/pair/{pairMerge}?force=true
     */
    public function destroy(Request $request, string $pairMerge): JsonResponse
    {
        $userGroup = $this->validateUserGroup($request);

        try {
            $result = app(PairMergeService::class)->unmerge($userGroup->id, (int) $pairMerge, $request->boolean('force'));
        } catch (PairRefusedException $e) {
            return $this->refused($e);
        }
        /** @var PairMerge $merge */
        $merge     = $result['merge'];
        $body      = $this->body($merge, $userGroup->id);
        $body['unmerged_at'] = $merge->unmerged_at?->toAtomString();

        return response()->json(['data' => $body])->header('Content-Type', self::JSON_CONTENT_TYPE);
    }

    /**
     * POST /api/v1/plaid-links/pair
     */
    public function store(Request $request): JsonResponse
    {
        $userGroup = $this->validateUserGroup($request);
        $data      = $request->validate([
            'keep_group_id'     => ['required', 'integer', 'min:1'],
            'absorb_group_id'   => ['required', 'integer', 'min:1', 'different:keep_group_id'],
            'keep_updated_at'   => ['required', 'string', 'date'],
            'absorb_updated_at' => ['required', 'string', 'date'],
            'evidence'          => ['nullable', 'array'],
        ]);

        try {
            $result = app(PairMergeService::class)->merge(
                $userGroup->id,
                (int) $data['keep_group_id'],
                (int) $data['absorb_group_id'],
                $data['keep_updated_at'],
                $data['absorb_updated_at'],
                $data['evidence'] ?? null,
            );
        } catch (PairRefusedException $e) {
            return $this->refused($e);
        }

        return response()->json(['data' => $this->body($result['merge'], $userGroup->id)])->header('Content-Type', self::JSON_CONTENT_TYPE);
    }

    /** @return array<string, mixed> */
    private function body(PairMerge $merge, int $userGroupId): array
    {
        $type = (string) \Illuminate\Support\Facades\DB::table('transaction_journals as j')->join('transaction_types as tt', 'tt.id', '=', 'j.transaction_type_id')
            ->where('j.id', $merge->keep_journal_id)->value('tt.type')
        ;

        return [
            'pair_merge_id'     => (string) $merge->id,
            'keep_group_id'     => (string) $merge->keep_group_id,
            'absorbed_group_id' => (string) $merge->absorbed_group_id,
            'type'              => strtolower($type),
            'transaction_group' => $this->group($merge->keep_group_id),
        ];
    }

    /** @return null|array<string, mixed> */
    private function group(int $groupId): ?array
    {
        /** @var User $admin */
        $admin     = auth()->user();
        $group     = TransactionGroup::find($groupId);
        if (null === $group) {
            return null;
        }

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($admin)->setUserGroup($this->userGroup)->setTransactionGroup($group)->withAPIInformation();
        $selected  = $collector->getGroups()->first();
        if (null === $selected) {
            return null;
        }
        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($admin);
        $selected   = $enrichment->enrichSingle($selected);

        /** @var TransactionGroupTransformer $transformer */
        $transformer = app(TransactionGroupTransformer::class);
        $transformer->setParameters($this->parameters);

        return $this->getManager()->createData(new Item($selected, $transformer, 'transactions'))->toArray();
    }

    private function refused(PairRefusedException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason] + $e->extra, $e->status)->header('Content-Type', self::JSON_CONTENT_TYPE);
    }
}
