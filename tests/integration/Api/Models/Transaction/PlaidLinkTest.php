<?php

/*
 * PlaidLinkTest.php
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

namespace Tests\integration\Api\Models\Transaction;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\PlaidTransactionLink;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Override;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class PlaidLinkTest extends TestCase
{
    use RefreshDatabase;

    private Account $checking;
    private Account $savings;
    private User $user;

    public function testDuplicateCreateReturns409AndWritesNothing(): void
    {
        $first  = $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'));
        $first->assertOk();
        $before = TransactionJournal::count();

        $second = $this->postJson(route('api.v1.transactions.store'), $this->payload('A1', 'a different description'));
        $second->assertStatus(409);
        $second->assertJsonPath('conflicts.0.plaid_transaction_id', 'A1');
        $second->assertJsonPath('conflicts.0.transaction_group_id', (int) $first->json('data.id'));
        $this->assertSame($before, TransactionJournal::count());
        $this->assertSame(1, PlaidTransactionLink::count());
    }

    public function testDuplicateInSecondLinkWritesNothingForTheFirst(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'))->assertOk();
        $payload                                        = $this->payload('B1');
        $payload['transactions'][0]['plaid_links'][]   = ['plaid_transaction_id' => 'A1', 'leg' => 'destination'];
        $this->postJson(route('api.v1.transactions.store'), $payload)->assertStatus(409);
        $this->assertSame(1, PlaidTransactionLink::count());
        $this->assertSame(1, TransactionJournal::count());
    }

    public function testTransferHasTwoLinksOnOneJournal(): void
    {
        $payload                           = $this->payload('S1');
        $payload['transactions'][0]['type'] = 'transfer';
        $payload['transactions'][0]['destination_id'] = (string) $this->savings->id;
        $payload['transactions'][0]['plaid_links'][0]['leg'] = 'source';
        $payload['transactions'][0]['plaid_links'][]         = ['plaid_transaction_id' => 'D1', 'leg' => 'destination', 'plaid_account_id' => 'plaid-sav'];
        $response = $this->postJson(route('api.v1.transactions.store'), $payload);
        $response->assertOk();
        $this->assertCount(2, $response->json('data.attributes.transactions.0.plaid_links'));
        $this->assertSame(1, PlaidTransactionLink::distinct()->count('transaction_journal_id'));
        $this->assertSame(2, PlaidTransactionLink::count());
    }

    public function testUpdateReplacesPendingWithPosted(): void
    {
        $group = $this->postJson(route('api.v1.transactions.store'), $this->payload('PENDING'))->json('data.id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['transactions' => [['plaid_links' => [['plaid_transaction_id' => 'POSTED', 'leg' => 'single']]]]])->assertOk();
        $this->assertSame(['POSTED'], PlaidTransactionLink::pluck('plaid_transaction_id')->all());
        // the old id is free again
        $this->postJson(route('api.v1.transactions.store'), $this->payload('PENDING', 'new'))->assertOk();
    }

    public function testUpdateAddsSecondLegAndConflictRollsBack(): void
    {
        $a = $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'))->json('data.id');
        $b = $this->postJson(route('api.v1.transactions.store'), $this->payload('B1'))->json('data.id');
        $url = route('api.v1.transactions.update', ['transactionGroup' => $a]);
        $this->putJson($url, ['transactions' => [['description' => 'renamed', 'plaid_links' => [['plaid_transaction_id' => 'A1', 'leg' => 'source'], ['plaid_transaction_id' => 'A2', 'leg' => 'destination']]]]])->assertOk();
        $this->assertSame(2, PlaidTransactionLink::where('transaction_journal_id', TransactionJournal::where('transaction_group_id', $a)->value('id'))->count());

        // B1 belongs to group b: 409, and the description change in the same request is rolled back.
        $this->putJson($url, ['transactions' => [['description' => 'must not stick', 'plaid_links' => [['plaid_transaction_id' => 'B1', 'leg' => 'single']]]]])
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.transaction_group_id', (int) $b)
        ;
        $this->assertSame('renamed', TransactionJournal::where('transaction_group_id', $a)->value('description'));
        $this->assertSame(3, PlaidTransactionLink::count());
    }

    public function testUpdateWithoutPlaidLinksKeepsThem(): void
    {
        $a = $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'))->json('data.id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $a]), ['transactions' => [['description' => 'x']]])->assertOk();
        $this->assertSame(1, PlaidTransactionLink::count());
    }

    public function testDeleteThenReimport(): void
    {
        $a = $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'))->json('data.id');
        $this->deleteJson(route('api.v1.transactions.delete', ['transactionGroup' => $a]))->assertNoContent();
        $this->assertSame(0, PlaidTransactionLink::count());
        $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'))->assertOk();
        $this->assertSame(1, PlaidTransactionLink::count());
    }

    public function testDeleteSingleJournalFreesLinks(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'))->assertOk();
        $journalId = TransactionJournal::value('id');
        $this->deleteJson(route('api.v1.transaction-journals.delete', ['tj' => $journalId]))->assertNoContent();
        $this->assertSame(0, PlaidTransactionLink::count());
    }

    public function testLookup(): void
    {
        $a = $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'))->json('data.id');
        $response = $this->getJson(route('api.v1.plaid-links.index', ['plaid_transaction_id' => ['A1', 'NOPE']]));
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.transaction_group_id', (string) $a);
        $response->assertJsonPath('data.0.leg', 'single');
    }

    public function testLookupDoesNotLeakOtherUserGroups(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->payload('A1'))->assertOk();
        $other = User::create(['email' => 'other@email.com', 'password' => 'password', 'user_group_id' => \FireflyIII\Models\UserGroup::create(['title' => 'other'])->id]);
        \FireflyIII\Models\GroupMembership::create(['user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'user_role_id' => \FireflyIII\Models\UserRole::where('title', 'owner')->value('id')]);
        $this->actingAs($other, 'api');
        $this->getJson(route('api.v1.plaid-links.index', ['plaid_transaction_id' => ['A1']]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function testInvalidLegIsRejected(): void
    {
        $payload                                      = $this->payload('A1');
        $payload['transactions'][0]['plaid_links'][0]['leg'] = 'bogus';
        $this->postJson(route('api.v1.transactions.store'), $payload)->assertStatus(422);
        $this->assertSame(0, TransactionJournal::count());
    }

    public function testCreateWithoutLinksStillWorks(): void
    {
        $payload = $this->payload('X');
        unset($payload['transactions'][0]['plaid_links']);
        $this->postJson(route('api.v1.transactions.store'), $payload)->assertOk()->assertJsonPath('data.attributes.transactions.0.plaid_links', []);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = $this->createAuthenticatedUser();
        $this->actingAs($this->user, 'api');
        $this->checking = Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create();
        $this->savings  = Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create();
    }

    private function payload(string $plaidId, string $description = 'coffee'): array
    {
        return [
            'apply_rules'  => false,
            'fire_webhooks' => false,
            'transactions' => [[
                'type'           => 'withdrawal',
                'date'           => '2026-10-01',
                'amount'         => '4.50',
                'description'    => $description,
                'source_id'      => (string) $this->checking->id,
                'destination_name' => 'Coffee shop',
                'plaid_links'    => [['plaid_transaction_id' => $plaidId, 'leg' => 'single', 'plaid_account_id' => 'plaid-chk']],
            ]],
        ];
    }
}
