<?php

/*
 * PairMergeTest.php
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

use FireflyIII\Events\Model\TransactionGroup\DestroyedSingleTransactionGroup;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Models\PairMerge;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Second half of the merge/unmerge tests (atomicity, user fields, events, isolation, unmerge); split from PairMergeTest
 * only so each group of scripts/test-pgsql.sh stays well under the 2 minute cap.
 *
 * @internal
 *
 * @coversNothing
 */
final class PairMergeStateTest extends PairTestCase
{
    // ---------------------------------------------------------------- 4: atomicity

    /** @return array<string, array{0: string}> */
    public static function injectionPoints(): array
    {
        return [
            'after the keep rows and the link move'  => ['update "plaid_transaction_links" set "transaction_journal_id"'],
            'after the soft delete of the absorbed'  => ['update "transaction_journals" set "deleted_at"'],
            'after the absorbed group soft delete'   => ['update "transaction_groups" set "deleted_at"'],
            'after the pair_merges row'              => ['insert into "pair_merges"'],
        ];
    }

    #[DataProvider('injectionPoints')]
    public function testAnExceptionAnywhereRollsEverythingBack(string $sqlPrefix): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['tags' => ['t1'], 'notes' => 'keep note'], ['tags' => ['t2'], 'notes' => 'abs note', 'category_name' => 'Cat']);
        $before       = $this->dump();
        $fired        = false;
        DB::listen(static function ($query) use ($sqlPrefix, &$fired): void {
            if (str_starts_with($query->sql, $sqlPrefix)) {
                $fired = true;

                throw new \RuntimeException('injected failure');
            }
        });
        $response = $this->merge($keep, $abs);
        $this->assertTrue($fired, 'the injection point was never reached: '.$sqlPrefix);
        $response->assertStatus(500);
        $this->assertDumpsEqual($before, $this->dump(), $sqlPrefix);
        $this->assertSame(0, PairMerge::count());
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
        $this->assertSame(['IN1' => 'single'], $this->linkLegs($abs['journal']));
        // and the merge still works afterwards (nothing was left half way, no stuck lock)
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
        $this->merge($this->ref($keep['group']), $this->ref($abs['group']))->assertOk();
        $this->assertSame(1, $this->liveMerges());
    }

    // ---------------------------------------------------------------- 6: user fields

    public function testUserFieldsAreMerged(): void
    {
        [$keep, $abs] = $this->pair(
            $this->assetA,
            $this->assetB,
            'OUT1',
            'IN1',
            ['tags' => ['shared', 'only-keep'], 'notes' => 'keep note', 'description' => 'Keep description'],
            ['tags' => ['shared', 'only-abs'], 'notes' => 'absorbed note', 'description' => 'Absorbed description', 'category_name' => 'Abs category'],
        );
        $this->merge($keep, $abs)->assertOk();
        $tags = DB::table('tag_transaction_journal as p')->join('tags', 'tags.id', '=', 'p.tag_id')->where('p.transaction_journal_id', $keep['journal'])->pluck('tags.tag')->all();
        $this->assertEqualsCanonicalizing(['shared', 'only-keep', 'only-abs'], $tags);
        $note = DB::table('notes')->where('noteable_id', $keep['journal'])->whereNull('deleted_at')->value('text');
        $this->assertSame("keep note\n\n--- merged from 2026-10-01 Absorbed description ---\nabsorbed note", $note);
        $this->assertSame('Keep description', TransactionJournal::find($keep['journal'])->description);
        // keep had no category: absorbed's
        $this->assertSame('Abs category', DB::table('category_transaction_journal as p')->join('categories', 'categories.id', '=', 'p.category_id')->where('p.transaction_journal_id', $keep['journal'])->value('categories.name'));
    }

    public function testKeepCategoryBudgetAndBillWinWhenSet(): void
    {
        $this->budgets('Keep budget', 'Abs budget');
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['category_name' => 'Keep cat', 'budget_name' => 'Keep budget'], ['category_name' => 'Abs cat', 'budget_name' => 'Abs budget']);
        $this->merge($keep, $abs)->assertOk();
        $cats = DB::table('category_transaction_journal as p')->join('categories', 'categories.id', '=', 'p.category_id')->where('p.transaction_journal_id', $keep['journal'])->pluck('categories.name')->all();
        $this->assertSame(['Keep cat'], $cats);
        $budgets = DB::table('budget_transaction_journal as p')->join('budgets', 'budgets.id', '=', 'p.budget_id')->where('p.transaction_journal_id', $keep['journal'])->pluck('budgets.name')->all();
        $this->assertSame(['Keep budget'], $budgets);
    }

    public function testNullFieldsOfKeepTakeTheAbsorbedBudgetAndBill(): void
    {
        $this->budgets('Abs budget');
        $billId       = DB::table('bills')->insertGetId(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Rent', 'amount_min' => 1, 'amount_max' => 2, 'date' => '2026-01-01', 'repeat_freq' => 'monthly', 'skip' => 0, 'match' => 'rent', 'automatch' => false, 'active' => true, 'transaction_currency_id' => DB::table('transaction_currencies')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1');
        // Firefly only stores a budget on a withdrawal, so a deposit never has one; the rows can still exist (imports, old data).
        DB::table('budget_transaction_journal')->insert(['budget_id' => DB::table('budgets')->value('id'), 'transaction_journal_id' => $abs['journal']]);
        DB::table('transaction_journals')->where('id', $abs['journal'])->update(['bill_id' => $billId]);
        $abs          = $this->ref($abs['group']);
        $this->merge($keep, $abs)->assertOk();
        $this->assertSame($billId, (int) DB::table('transaction_journals')->where('id', $keep['journal'])->value('bill_id'));
        $this->assertSame(1, DB::table('budget_transaction_journal')->where('transaction_journal_id', $keep['journal'])->count());
    }

    public function testIdenticalOrEmptyAbsorbedNotesAreNotAppended(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['notes' => 'same'], ['notes' => 'same']);
        $this->merge($keep, $abs)->assertOk();
        $this->assertSame('same', DB::table('notes')->where('noteable_id', $keep['journal'])->whereNull('deleted_at')->value('text'));
        [$keep2, $abs2] = $this->pair($this->assetA, $this->assetB, 'OUT2', 'IN2', ['notes' => 'only keep'], []);
        $this->merge($keep2, $abs2)->assertOk();
        $this->assertSame('only keep', DB::table('notes')->where('noteable_id', $keep2['journal'])->whereNull('deleted_at')->value('text'));
    }

    // ---------------------------------------------------------------- events after commit

    public function testEventsAreFiredAfterTheTransactionHasCommitted(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $base         = DB::transactionLevel();
        $levels       = [];
        Event::listen(UpdatedSingleTransactionGroup::class, static function () use (&$levels): void {
            $levels['updated'] = DB::transactionLevel();
        });
        Event::listen(DestroyedSingleTransactionGroup::class, static function () use (&$levels): void {
            $levels['destroyed'] = DB::transactionLevel();
        });
        $this->merge($keep, $abs)->assertOk();
        $this->assertSame(['updated' => $base, 'destroyed' => $base], $levels);
    }

    public function testNoEventsWhenTheMergeIsRefusedOrRetried(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->merge($keep, $abs)->assertOk();
        Event::fake([UpdatedSingleTransactionGroup::class, DestroyedSingleTransactionGroup::class]);
        $this->merge($keep, $abs)->assertOk();
        $this->merge($keep, $abs, ['absorb_group_id' => '999999'])->assertStatus(404);
        Event::assertNotDispatched(UpdatedSingleTransactionGroup::class);
        Event::assertNotDispatched(DestroyedSingleTransactionGroup::class);
    }

    // ---------------------------------------------------------------- 8: isolation and roles

    public function testAnotherUserGroupCannotMergeOrUnmerge(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $stranger     = $this->otherUser('stranger@email.com');
        $this->actingAs($stranger, 'api');
        $before       = $this->dump();
        $this->merge($keep, $abs)->assertStatus(404);
        $this->actingAs($this->user, 'api');
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->actingAs($stranger, 'api');
        $this->unmerge($id)->assertStatus(404);
        $this->actingAs($this->user, 'api');
        $this->assertSame(1, $this->liveMerges());
        $this->assertNotEmpty($before);
    }

    public function testReadOnlyRoleCannotMergeOrUnmerge(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $reader       = User::create(['email' => 'reader@email.com', 'password' => 'password', 'user_group_id' => $this->user->user_group_id]);
        \FireflyIII\Models\GroupMembership::create(['user_id' => $reader->id, 'user_group_id' => $this->user->user_group_id, 'user_role_id' => \FireflyIII\Models\UserRole::where('title', 'ro')->value('id')]);
        $this->actingAs($reader, 'api');
        $this->merge($keep, $abs)->assertStatus(401);
        $this->assertSame(0, PairMerge::count());
        $this->actingAs($this->user, 'api');
        $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->actingAs($reader, 'api');
        $this->unmerge($id)->assertStatus(401);
        $this->assertSame(1, $this->liveMerges());
    }

    public function testUnauthenticatedIs401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson(route('api.v1.plaid-links.pair.store'), [])->assertStatus(401);
    }

    // ---------------------------------------------------------------- 7: unmerge

    /** The guarantee of section 6: merge then unmerge leaves every touched table as it was (updated_at aside). */
    public function testMergeThenUnmergeRestoresEveryTouchedTable(): void
    {
        $this->budgets('Abs budget');
        [$keep, $abs] = $this->pair(
            $this->assetA,
            $this->assetB,
            'OUT1',
            'IN1',
            ['tags' => ['k1'], 'notes' => 'keep note', 'category_name' => 'Keep cat'],
            ['tags' => ['k2', 'k1'], 'notes' => 'abs note'],
        );
        DB::table('budget_transaction_journal')->insert(['budget_id' => DB::table('budgets')->value('id'), 'transaction_journal_id' => $abs['journal']]);
        DB::table('journal_meta')->insert(['transaction_journal_id' => $abs['journal'], 'name' => 'internal_reference', 'data' => json_encode('REF'), 'hash' => hash('sha256', 'REF'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('locations')->insert(['locatable_id' => $abs['journal'], 'locatable_type' => TransactionJournal::class, 'latitude' => 1.5, 'longitude' => 2.5, 'zoom_level' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $abs    = $this->ref($abs['group']);
        $before = $this->dump();
        $id     = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->assertNotEquals($before['transaction_journals'], $this->dump()['transaction_journals']);
        $noteOf   = static fn (int $journal) => DB::table('notes')->where('noteable_id', $journal)->whereNull('deleted_at')->value('text');
        $this->assertStringContainsString('abs note', (string) $noteOf($keep['journal']), 'the merge must have changed keep\'s note');
        $response = $this->unmerge($id);
        $response->assertOk();
        $response->assertJsonPath('data.pair_merge_id', $id);
        $this->assertNotNull($response->json('data.unmerged_at'));
        $this->assertSame('keep note', $noteOf($keep['journal']), 'unmerge must restore keep\'s own note text');
        $this->assertSame('abs note', $noteOf($abs['journal']));
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertNotNull(PairMerge::find($id)->unmerged_at);
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
        $this->assertSame(['IN1' => 'single'], $this->linkLegs($abs['journal']));
        // the pair can be merged again after an unmerge (the unique index only covers live merges)
        $this->merge($this->ref($keep['group']), $this->ref($abs['group']))->assertOk();
        $this->assertSame(2, PairMerge::count());
        $this->assertSame(1, $this->liveMerges());
    }

    public function testUnmergeTwiceIsIdempotent(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $first        = $this->unmerge($id)->assertOk();
        $after        = $this->dump();
        $second       = $this->unmerge($id)->assertOk();
        $this->assertSame($first->json('data.pair_merge_id'), $second->json('data.pair_merge_id'));
        $this->assertSame($first->json('data.unmerged_at'), $second->json('data.unmerged_at'));
        $this->assertDumpsEqual($after, $this->dump());
    }

    public function testUnmergeOfAnUnknownIdIs404(): void
    {
        $this->unmerge(999999)->assertStatus(404);
    }

    /** C7: edits made after the merge survive the unmerge; only merge-changed fields are restored. */
    public function testEditAfterMergeSurvivesUnmerge(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['tags' => ['k1']], ['tags' => ['k2']]);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['description' => 'edited after merge', 'tags' => ['k1', 'k2', 'mine']]]])->assertOk();
        $this->assertSame('edited after merge', TransactionJournal::find($keep['journal'])->description);
        $this->unmerge($id)->assertOk();
        $this->assertSame('edited after merge', TransactionJournal::find($keep['journal'])->description);
        $tags = DB::table('tag_transaction_journal as p')->join('tags', 'tags.id', '=', 'p.tag_id')->where('p.transaction_journal_id', $keep['journal'])->pluck('tags.tag')->all();
        $this->assertEqualsCanonicalizing(['k1', 'mine'], $tags);
        $this->assertSame('Withdrawal', TransactionJournal::with('transactionType')->find($keep['journal'])->transactionType->type);
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
        $this->assertSame(0, DB::table('transaction_journals')->where('id', $abs['journal'])->whereNotNull('deleted_at')->count());
    }

    /** C7: the edit survives the merge itself too (a PUT to the merged keep persists). */
    public function testPutToMergedKeepPersists(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $this->merge($keep, $abs)->assertOk();
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['description' => 'after', 'tags' => ['t']]]])->assertOk();
        $this->assertSame('after', TransactionJournal::find($keep['journal'])->description);
        $this->assertSame(['IN1' => 'destination', 'OUT1' => 'source'], $this->linkLegs($keep['journal']));
    }

    /** C7: a conflicting merge-changed field is a 409 diverged naming the field; force restores it anyway. */
    public function testConflictingEditIsDivergedAndForceOverrides(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', [], ['category_name' => 'From absorbed']);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['description' => 'fine', 'category_name' => 'User chose this']]])->assertOk();
        $before = $this->dump();
        $res    = $this->unmerge($id)->assertStatus(409);
        $res->assertJsonPath('reason', 'diverged');
        $this->assertSame(['category'], array_column($res->json('fields'), 'field'));
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertNull(PairMerge::find($id)->unmerged_at);

        $this->unmerge($id, true)->assertOk();
        $this->assertSame(0, DB::table('category_transaction_journal')->where('transaction_journal_id', $keep['journal'])->count());
        $this->assertSame('fine', TransactionJournal::find($keep['journal'])->description);
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
    }

    public function testEditedNotesAreDiverged(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['notes' => 'n1'], ['notes' => 'n2']);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['notes' => 'rewritten']]])->assertOk();
        $res = $this->unmerge($id)->assertStatus(409);
        $this->assertSame(['notes'], array_column($res->json('fields'), 'field'));
    }

    public function testRemovedLinkIsDiverged(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        DB::table('plaid_transaction_links')->where('plaid_transaction_id', 'IN1')->delete();
        $res          = $this->unmerge($id)->assertStatus(409);
        $this->assertSame(['plaid_links'], array_column($res->json('fields'), 'field'));
        // forced: the absorbed id is re-created on the restored journal
        $this->unmerge($id, true)->assertOk();
        $this->assertSame(['IN1' => 'single'], $this->linkLegs($abs['journal']));
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
    }

    /** The absorbed rows were purged (Firefly's "purge deleted data"): unmerge re-creates them from the snapshot. */
    public function testUnmergeAfterTheAbsorbedRowsWerePurged(): void
    {
        $this->budgets('Bud');
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', [], ['tags' => ['k2'], 'notes' => 'abs note', 'category_name' => 'Cat']);
        DB::table('budget_transaction_journal')->insert(['budget_id' => DB::table('budgets')->value('id'), 'transaction_journal_id' => $abs['journal']]);
        DB::table('journal_meta')->insert(['transaction_journal_id' => $abs['journal'], 'name' => 'internal_reference', 'data' => json_encode('REF'), 'hash' => hash('sha256', 'REF'), 'created_at' => now(), 'updated_at' => now()]);
        $abs          = $this->ref($abs['group']);
        $before       = $this->dump();
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $j            = $abs['journal'];
        foreach (['journal_meta', 'transactions', 'tag_transaction_journal', 'category_transaction_journal', 'budget_transaction_journal'] as $table) {
            DB::table($table)->where('transaction_journal_id', $j)->delete();
        }
        DB::table('notes')->where('noteable_id', $j)->where('noteable_type', TransactionJournal::class)->delete();
        DB::table('transaction_journals')->where('id', $j)->delete();
        DB::table('transaction_groups')->where('id', $abs['group'])->delete();
        $this->assertSame(0, DB::table('transaction_journals')->where('id', $j)->count());
        $this->unmerge($id)->assertOk();
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertSame(['IN1' => 'single'], $this->linkLegs($j));
        $this->assertSame(1, TransactionGroup::where('id', $abs['group'])->count());
    }

    /** The absorbed Plaid id was freed and imported again as a different journal: 409 link_conflict, nothing changes. */
    public function testUnmergeWhenTheAbsorbedPlaidIdWasReimportedIsLinkConflict(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        DB::table('plaid_transaction_links')->where('plaid_transaction_id', 'IN1')->delete();
        $again        = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'IN1', 'description' => 'imported again']);
        $before       = $this->dump();
        foreach ([false, true] as $force) {
            $res = $this->unmerge($id, $force)->assertStatus(409);
            $res->assertJsonPath('reason', 'link_conflict');
            $res->assertJsonPath('conflicts.0.plaid_transaction_id', 'IN1');
            $res->assertJsonPath('conflicts.0.transaction_group_id', $again['group']);
            $this->assertDumpsEqual($before, $this->dump());
        }
        $this->assertNull(PairMerge::find($id)->unmerged_at);
    }

    public function testUnmergeWhenKeepWasDeletedIsRefused(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $this->deleteJson(route('api.v1.transactions.delete', ['transactionGroup' => $keep['group']]))->assertNoContent();
        $before       = $this->dump();
        $this->unmerge($id, true)->assertStatus(409)->assertJsonPath('reason', 'keep_missing');
        $this->assertDumpsEqual($before, $this->dump());
    }

    /** Atomicity of the unmerge: an exception half way restores nothing partially. */
    #[DataProvider('unmergeInjectionPoints')]
    public function testAnExceptionDuringUnmergeRollsBack(string $sqlPrefix): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB, 'OUT1', 'IN1', ['tags' => ['t1']], ['tags' => ['t2'], 'notes' => 'abs']);
        $id           = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');
        $before       = $this->dump();
        $fired        = false;
        DB::listen(static function ($query) use ($sqlPrefix, &$fired): void {
            if (str_starts_with($query->sql, $sqlPrefix)) {
                $fired = true;

                throw new \RuntimeException('injected failure');
            }
        });
        $this->unmerge($id)->assertStatus(500);
        $this->assertTrue($fired, 'the injection point was never reached: '.$sqlPrefix);
        $this->assertDumpsEqual($before, $this->dump());
        $this->assertNull(PairMerge::find($id)->unmerged_at);
    }

    /** @return array<string, array{0: string}> */
    public static function unmergeInjectionPoints(): array
    {
        return [
            'after the absorbed link moved back' => ['update "plaid_transaction_links" set "transaction_journal_id"'],
            'after the merge row is closed'      => ['update "pair_merges" set "unmerged_at"'],
        ];
    }
}
