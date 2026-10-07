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
use FireflyIII\Exceptions\PlaidLinkConflictException;
use FireflyIII\Models\Account;
use FireflyIII\Models\PlaidTransactionLink;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Services\Internal\Support\PlaidLinkService;
use FireflyIII\User;
use Illuminate\Database\QueryException;
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
        // the stored VALUES changed, not just the row count (this is what F1 hid on SQLite)
        $this->assertSame('source', PlaidTransactionLink::where('plaid_transaction_id', 'A1')->value('leg'));
        $this->assertSame('destination', PlaidTransactionLink::where('plaid_transaction_id', 'A2')->value('leg'));

        // B1 belongs to group b: 409, and the description change in the same request is rolled back.
        $this->putJson($url, ['transactions' => [['description' => 'must not stick', 'plaid_links' => [['plaid_transaction_id' => 'B1', 'leg' => 'single']]]]])
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.transaction_group_id', (int) $b)
        ;
        $this->assertSame('renamed', TransactionJournal::where('transaction_group_id', $a)->value('description'));
        $this->assertSame(3, PlaidTransactionLink::count());
    }

    public function testSameJournalTwiceInOnePutIs422(): void
    {
        [$group, $holder] = $this->splitGroup();
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), [
            'group_title'  => 'g',
            'transactions' => [
                ['transaction_journal_id' => $holder, 'plaid_links' => [['plaid_transaction_id' => 'M1', 'leg' => 'single']]],
                ['transaction_journal_id' => $holder, 'plaid_links' => []],
            ],
        ])->assertStatus(422);
        $this->assertSame($holder, (int) PlaidTransactionLink::where('plaid_transaction_id', 'M1')->value('transaction_journal_id'));
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

    public function testUpdateChangesPlaidAccountIdOfExistingId(): void
    {
        $a = $this->postJson(route('api.v1.transactions.store'), $this->payload('P1'))->json('data.id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $a]), ['transactions' => [['plaid_links' => [['plaid_transaction_id' => 'P1', 'leg' => 'single', 'plaid_account_id' => 'changed']]]]])->assertOk()
            ->assertJsonPath('data.attributes.transactions.0.plaid_links.0.plaid_account_id', 'changed')
        ;
        $this->assertSame('changed', PlaidTransactionLink::where('plaid_transaction_id', 'P1')->value('plaid_account_id'));
        $this->assertSame('single', PlaidTransactionLink::where('plaid_transaction_id', 'P1')->value('leg'));
    }

    public function testUpdateWithNullPlaidLinksRemovesAll(): void
    {
        $a = $this->postJson(route('api.v1.transactions.store'), $this->payload('N1'))->json('data.id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $a]), ['transactions' => [['plaid_links' => null]]])->assertOk();
        $this->assertSame(0, PlaidTransactionLink::count());
    }

    public function testSameIdInTwoSplitsIsA422AndWritesNothing(): void
    {
        $payload                                   = $this->payload('SPLIT');
        $payload['group_title']                    = 'split';
        $payload['transactions'][1]                = $payload['transactions'][0];
        $payload['transactions'][1]['description'] = 'second';
        $this->postJson(route('api.v1.transactions.store'), $payload)->assertStatus(422);
        $this->assertSame(0, TransactionJournal::count());
        $this->assertSame(0, PlaidTransactionLink::count());
    }

    public function testOppositeOrderSecondRequestIs409(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->twoLegPayload('Z9', 'A1'))->assertOk();
        $res = $this->postJson(route('api.v1.transactions.store'), $this->twoLegPayload('A1', 'Z9'))->assertStatus(409);
        $this->assertCount(2, $res->json('conflicts'));
    }

    /** Insert order (not read-back order) is sorted: this is the lock order that prevents deadlocks (F6). */
    public function testInsertOrderIsSorted(): void
    {
        $order = [];
        PlaidTransactionLink::creating(static function (PlaidTransactionLink $l) use (&$order): void {
            $order[] = $l->plaid_transaction_id;
        });
        $this->postJson(route('api.v1.transactions.store'), $this->twoLegPayload('Z9', 'A1'))->assertOk();
        $this->assertSame(['A1', 'Z9'], $order);
    }

    /** Postgres: locks for the Plaid ids of ALL splits are taken once, in sorted order, before any write (R2-5). */
    public function testAdvisoryLocksAreTakenInSortedOrderAcrossSplits(): void
    {
        if ('pgsql' !== \Illuminate\Support\Facades\DB::connection()->getDriverName()) {
            $this->markTestSkipped('advisory locks are Postgres only');
        }
        $locks = [];
        \Illuminate\Support\Facades\DB::listen(static function ($q) use (&$locks): void {
            if (str_contains($q->sql, 'pg_advisory_xact_lock')) {
                $locks[] = $q->bindings[0];
            }
        });
        $this->postJson(route('api.v1.transactions.store'), $this->splitPayload([['Z9'], ['A1']]))->assertOk();
        $gid = $this->user->user_group_id;
        $this->assertSame([$gid.':A1', $gid.':Z9'], $locks);
    }

    /** Both splits of a create conflict: every conflicting id is listed (R2-2). */
    public function testConflictsInTwoSplitsAreAllListed(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->twoLegPayload('C1', 'C2'))->assertOk();
        $res = $this->postJson(route('api.v1.transactions.store'), $this->splitPayload([['C1'], ['C2']]))->assertStatus(409);
        $this->assertEqualsCanonicalizing(['C1', 'C2'], array_column($res->json('conflicts'), 'plaid_transaction_id'));
        $this->assertSame(1, TransactionJournal::count());
    }

    /** Update path: conflicts of every split are listed and the whole update is rolled back. */
    public function testUpdateConflictsInTwoSplitsAreAllListed(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->twoLegPayload('C1', 'C2'))->assertOk();
        [$group, $j1, $j2] = $this->splitGroup();
        $res = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['group_title' => 'split', 'transactions' => [
            ['transaction_journal_id' => (string) $j1, 'description' => 'changed', 'plaid_links' => [['plaid_transaction_id' => 'C1', 'leg' => 'single']]],
            ['transaction_journal_id' => (string) $j2, 'plaid_links' => [['plaid_transaction_id' => 'C2', 'leg' => 'single']]],
        ]])->assertStatus(409);
        $this->assertEqualsCanonicalizing(['C1', 'C2'], array_column($res->json('conflicts'), 'plaid_transaction_id'));
        $this->assertNotSame('changed', TransactionJournal::find($j1)->description);
    }

    /** R2-1: moving an id between splits works whichever split is listed first. */
    public function testMoveIdBetweenSplitsReceiverListedFirst(): void
    {
        [$group, $holder, $other] = $this->splitGroup();
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['group_title' => 'split', 'transactions' => [
            ['transaction_journal_id' => (string) $other, 'plaid_links' => [['plaid_transaction_id' => 'M1', 'leg' => 'single']]],
            ['transaction_journal_id' => (string) $holder, 'plaid_links' => []],
        ]])->assertOk();
        $this->assertSame($other, PlaidTransactionLink::where('plaid_transaction_id', 'M1')->value('transaction_journal_id'));
        $this->assertSame(1, PlaidTransactionLink::count());
    }

    public function testMoveIdBetweenSplitsGiverListedFirst(): void
    {
        [$group, $holder, $other] = $this->splitGroup();
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['group_title' => 'split', 'transactions' => [
            ['transaction_journal_id' => (string) $holder, 'plaid_links' => []],
            ['transaction_journal_id' => (string) $other, 'plaid_links' => [['plaid_transaction_id' => 'M1', 'leg' => 'single']]],
        ]])->assertOk();
        $this->assertSame($other, PlaidTransactionLink::where('plaid_transaction_id', 'M1')->value('transaction_journal_id'));
    }

    /** The giving split is not in the request at all. */
    public function testMoveIdWhenGivingSplitIsOmitted(): void
    {
        [$group, $holder, $other] = $this->splitGroup();
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['transactions' => [
            ['transaction_journal_id' => (string) $other, 'plaid_links' => [['plaid_transaction_id' => 'M1', 'leg' => 'single']]],
        ]])->assertOk();
        $this->assertSame(1, PlaidTransactionLink::count());
    }

    /** A move must not steal an id that belongs to another group's journal; and a real conflict stays a 409. */
    public function testIdOnAnotherGroupsTransactionIsStillA409(): void
    {
        $other = $this->postJson(route('api.v1.transactions.store'), $this->payload('X1'))->assertOk()->json('data.id');
        [$group, , $j2] = $this->splitGroup();
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['transactions' => [
            ['transaction_journal_id' => (string) $j2, 'plaid_links' => [['plaid_transaction_id' => 'X1', 'leg' => 'single']]],
        ]])->assertStatus(409)->assertJsonPath('conflicts.0.transaction_group_id', (int) $other);
        $this->assertSame(1, PlaidTransactionLink::where('plaid_transaction_id', 'X1')->count());
        $this->assertSame(1, PlaidTransactionLink::where('plaid_transaction_id', 'M1')->count());
    }

    /** An FK violation must not be reported as "already imported" (409 means a different transaction only). */
    public function testForeignKeyViolationIsNotAConflict(): void
    {
        if ('pgsql' !== \Illuminate\Support\Facades\DB::connection()->getDriverName()) {
            $this->markTestSkipped('SQLite connection has no FK enforcement in Firefly config');
        }
        $journal                = new TransactionJournal();
        $journal->id            = 987654;
        $journal->user_group_id = $this->user->user_group_id;
        $caught                 = null;

        try {
            app(PlaidLinkService::class)->sync($journal, [['plaid_transaction_id' => 'FK1', 'leg' => 'single', 'plaid_account_id' => null]]);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        $this->assertNotInstanceOf(PlaidLinkConflictException::class, $caught);
        $this->assertInstanceOf(QueryException::class, $caught);
    }

    /** A CHECK violation (bad leg reaching the database) is not a conflict either, and the constraint exists. */
    public function testCheckViolationIsNotAConflict(): void
    {
        [, $j1] = $this->splitGroup();
        $caught = null;

        try {
            app(PlaidLinkService::class)->sync(TransactionJournal::find($j1), [['plaid_transaction_id' => 'CK1', 'leg' => 'bogus', 'plaid_account_id' => null]]);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        $this->assertNotInstanceOf(PlaidLinkConflictException::class, $caught);
        $this->assertInstanceOf(QueryException::class, $caught);
    }

    /** The keyed update must never touch another user group's row with the same Plaid id. */
    public function testKeyedUpdateDoesNotTouchOtherGroup(): void
    {
        [, $j1] = $this->splitGroup();
        $group  = \FireflyIII\Models\UserGroup::create(['title' => 'other']);
        $other  = User::create(['email' => 'other3@email.com', 'password' => 'password', 'user_group_id' => $group->id]);
        \FireflyIII\Models\GroupMembership::create(['user_id' => $other->id, 'user_group_id' => $group->id, 'user_role_id' => \FireflyIII\Models\UserRole::where('title', 'owner')->value('id')]);
        $oacc   = Account::factory()->for($other)->withType(AccountTypeEnum::ASSET)->create(['user_group_id' => $group->id]);
        $this->actingAs($other, 'api');
        $p                                       = $this->payload('M1');
        $p['transactions'][0]['source_id']       = (string) $oacc->id;
        $p['transactions'][0]['plaid_links'][0]['plaid_account_id'] = 'theirs';
        $this->postJson(route('api.v1.transactions.store'), $p)->assertOk();
        $this->actingAs($this->user, 'api');
        app(PlaidLinkService::class)->sync(TransactionJournal::find($j1), [['plaid_transaction_id' => 'M1', 'leg' => 'source', 'plaid_account_id' => 'mine']]);
        $theirs = PlaidTransactionLink::where('user_group_id', $other->user_group_id)->where('plaid_transaction_id', 'M1');
        $this->assertSame('theirs', $theirs->value('plaid_account_id'));
        $this->assertSame('single', $theirs->value('leg'));
        $this->assertSame('mine', PlaidTransactionLink::where('user_group_id', $this->user->user_group_id)->where('plaid_transaction_id', 'M1')->value('plaid_account_id'));
    }

    /** Update: the Postgres advisory locks cover every id of the request, sorted (R2-5). */
    public function testAdvisoryLocksOnUpdateAreSorted(): void
    {
        if ('pgsql' !== \Illuminate\Support\Facades\DB::connection()->getDriverName()) {
            $this->markTestSkipped('advisory locks are Postgres only');
        }
        [$group, $holder, $other] = $this->splitGroup();
        $locks = [];
        \Illuminate\Support\Facades\DB::listen(static function ($q) use (&$locks): void {
            if (str_contains($q->sql, 'pg_advisory_xact_lock')) {
                $locks[] = $q->bindings[0];
            }
        });
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['group_title' => 'split', 'transactions' => [
            ['transaction_journal_id' => (string) $holder, 'plaid_links' => [['plaid_transaction_id' => 'M1', 'leg' => 'single']]],
            ['transaction_journal_id' => (string) $other, 'plaid_links' => [['plaid_transaction_id' => 'B2', 'leg' => 'single'], ['plaid_transaction_id' => 'A0', 'leg' => 'single']]],
        ]])->assertOk();
        $gid = $this->user->user_group_id;
        $this->assertSame([$gid.':A0', $gid.':B2', $gid.':M1'], $locks);
    }

    /** Update adding NEW splits: conflicts of every new split are listed. */
    public function testUpdateNewSplitsConflictsAreAllListed(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->twoLegPayload('C1', 'C2'))->assertOk();
        [$group] = $this->splitGroup();
        $new1    = $this->payload('unused')['transactions'][0];
        $new2    = $new1;
        $new1['plaid_links'] = [['plaid_transaction_id' => 'C1', 'leg' => 'single']];
        $new2['plaid_links'] = [['plaid_transaction_id' => 'C2', 'leg' => 'single']];
        $res = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group]), ['group_title' => 'split', 'transactions' => [$new1, $new2]])->assertStatus(409);
        $this->assertEqualsCanonicalizing(['C1', 'C2'], array_column($res->json('conflicts'), 'plaid_transaction_id'));
    }

    /** R2-4: the row is moved to another journal between the read and the keyed update: no theft, a conflict. */
    public function testKeyedUpdateDoesNotStealARowMovedUnderIt(): void
    {
        [, $j1, $j2] = $this->splitGroup();   // M1 sits on j1
        $moved       = false;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$moved, $j2): void {
            if (!$moved && str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'plaid_transaction_links')) {
                $moved = true;
                \Illuminate\Support\Facades\DB::table('plaid_transaction_links')->where('plaid_transaction_id', 'M1')->update(['transaction_journal_id' => $j2]);
            }
        });
        try {
            app(PlaidLinkService::class)->sync(TransactionJournal::find($j1), [['plaid_transaction_id' => 'M1', 'leg' => 'source', 'plaid_account_id' => null]]);
            $this->fail('expected a conflict');
        } catch (PlaidLinkConflictException $e) {
            $this->assertSame($j2, $e->conflicts[0]['transaction_journal_id']);
        }
        $this->assertSame($j2, (int) PlaidTransactionLink::where('plaid_transaction_id', 'M1')->value('transaction_journal_id'));
    }

    /** R2-6a: an identical concurrent request wrote the same row for THIS journal first: desired state holds, no conflict. */
    public function testRaceLoserWhoseDesiredStateHoldsSucceeds(): void
    {
        [, , $j2] = $this->splitGroup();
        $gid      = $this->user->user_group_id;
        $injected = false;
        \Illuminate\Support\Facades\DB::listen(static function ($q) use (&$injected, $gid, $j2): void {
            // right after the existence check returned nothing, the "other request" commits the same row.
            if (!$injected && str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'plaid_transaction_links')) {
                $injected = true;
                \Illuminate\Support\Facades\DB::table('plaid_transaction_links')->insert(['user_group_id' => $gid, 'plaid_transaction_id' => 'R1', 'transaction_journal_id' => $j2, 'leg' => 'single']);
            }
        });
        app(PlaidLinkService::class)->sync(TransactionJournal::find($j2), [['plaid_transaction_id' => 'R1', 'leg' => 'single', 'plaid_account_id' => 'pa']]);
        $this->assertSame('pa', PlaidTransactionLink::where('plaid_transaction_id', 'R1')->value('plaid_account_id'));
        $this->assertSame(1, PlaidTransactionLink::where('plaid_transaction_id', 'R1')->count());
    }

    /** R2-6b: the winner vanishes (its insert is rolled back) before the loser re-reads: the id is free, retry, no 409. */
    public function testRaceLoserWhoseWinnerVanishedRetries(): void
    {
        [, , $j2] = $this->splitGroup();
        $gid      = $this->user->user_group_id;
        PlaidTransactionLink::creating(static function (PlaidTransactionLink $l) use ($j2, $gid): void {
            static $done = false;
            if (!$done) {
                $done = true;
                // inside the savepoint: the failed insert rolls this row back too, so the re-read finds nothing.
                \Illuminate\Support\Facades\DB::table('plaid_transaction_links')->insert(['user_group_id' => $gid, 'plaid_transaction_id' => 'R2', 'transaction_journal_id' => $j2, 'leg' => 'single']);
            }
        });
        app(PlaidLinkService::class)->sync(TransactionJournal::find($j2), [['plaid_transaction_id' => 'R2', 'leg' => 'single', 'plaid_account_id' => null]]);
        $this->assertSame(1, PlaidTransactionLink::where('plaid_transaction_id', 'R2')->count());
    }

    /** leg is constrained in the database (3c4ea5154d). */
    public function testLegColumnIsAnEnum(): void
    {
        $this->expectException(QueryException::class);
        [, $j1] = $this->splitGroup();
        PlaidTransactionLink::query()->insert(['user_group_id' => $this->user->user_group_id, 'plaid_transaction_id' => 'E1', 'transaction_journal_id' => $j1, 'leg' => 'bogus']);
    }

    public function testAccountDeleteFreesLinksAndReimportWorks(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->payload('ACC1'))->assertOk();
        $this->deleteJson(route('api.v1.accounts.delete', ['account' => $this->checking->id]))->assertNoContent();
        $this->assertSame(0, PlaidTransactionLink::count());
        $this->checking = Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create();
        $this->postJson(route('api.v1.transactions.store'), $this->payload('ACC1'))->assertOk();
        $this->assertSame(1, PlaidTransactionLink::count());
    }

    public function testOtherGroupCanImportSameIdWithoutConflict(): void
    {
        $this->postJson(route('api.v1.transactions.store'), $this->payload('SHARED'))->assertOk();
        $group = \FireflyIII\Models\UserGroup::create(['title' => 'other']);
        $other = User::create(['email' => 'other2@email.com', 'password' => 'password', 'user_group_id' => $group->id]);
        \FireflyIII\Models\GroupMembership::create(['user_id' => $other->id, 'user_group_id' => $group->id, 'user_role_id' => \FireflyIII\Models\UserRole::where('title', 'owner')->value('id')]);
        $this->actingAs($other, 'api');
        $acct                                    = Account::factory()->for($other)->withType(AccountTypeEnum::ASSET)->create(['user_group_id' => $group->id]);
        $payload                                 = $this->payload('SHARED');
        $payload['transactions'][0]['source_id'] = (string) $acct->id;
        $this->postJson(route('api.v1.transactions.store'), $payload)->assertOk();
        $this->assertSame(2, PlaidTransactionLink::count());
    }

    public function testInvalidLegIsRejected(): void
    {
        $payload                                      = $this->payload('A1');
        $payload['transactions'][0]['plaid_links'][0]['leg'] = 'bogus';
        $this->postJson(route('api.v1.transactions.store'), $payload)->assertStatus(422);
        $this->assertSame(0, TransactionJournal::count());
    }

    public function testMissingPlaidAccountIdIsNull(): void
    {
        $payload = $this->payload('A1');
        unset($payload['transactions'][0]['plaid_links'][0]['plaid_account_id']);
        $this->postJson(route('api.v1.transactions.store'), $payload)->assertOk()->assertJsonPath('data.attributes.transactions.0.plaid_links.0.plaid_account_id', null);
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

    /** @param array<int, array<int, string>> $splits plaid ids per split @return array */
    private function splitPayload(array $splits): array
    {
        $payload                = $this->payload('unused');
        $payload['group_title'] = 'split';
        $payload['transactions'] = [];
        foreach ($splits as $i => $ids) {
            $row                = $this->payload('unused')['transactions'][0];
            $row['description'] = 'split '.$i;
            $row['plaid_links'] = array_map(static fn (string $id): array => ['plaid_transaction_id' => $id, 'leg' => 'single'], $ids);
            $payload['transactions'][] = $row;
        }

        return $payload;
    }

    /** @return array{0:int,1:int,2:int} group, journal holding M1, journal without links */
    private function splitGroup(): array
    {
        $res    = $this->postJson(route('api.v1.transactions.store'), $this->splitPayload([['M1'], []]))->assertOk();
        $holder = (int) PlaidTransactionLink::where('plaid_transaction_id', 'M1')->value('transaction_journal_id');
        $ids    = array_map('intval', array_column($res->json('data.attributes.transactions'), 'transaction_journal_id'));
        $other  = $ids[0] === $holder ? $ids[1] : $ids[0];

        return [(int) $res->json('data.id'), $holder, $other];
    }

    private function twoLegPayload(string $first, string $second): array
    {
        $payload                                     = $this->payload($first);
        $payload['transactions'][0]['type']           = 'transfer';
        $payload['transactions'][0]['destination_id'] = (string) $this->savings->id;
        unset($payload['transactions'][0]['destination_name']);
        $payload['transactions'][0]['plaid_links'][]  = ['plaid_transaction_id' => $second, 'leg' => 'destination'];

        return $payload;
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
