<?php

/*
 * PairConcurrencyTest.php
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
use FireflyIII\Models\PairMerge;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * Real concurrent connections against Postgres (design transfer-pairer.md section 10, test 5).
 *
 * Every request runs in a forked child with its OWN database connection and its own committed transaction, so the
 * advisory locks and row locks are the real ones. The parent only prepares data (committed, no wrapping transaction)
 * and judges the final state. Children are killed with SIGKILL after reporting, so the parent's socket is never closed.
 *
 * The "held" scenarios are deterministic: the first child sleeps right after it took its locks, the second child
 * starts only once the first reports that it holds them, so the second MUST queue behind the first.
 *
 * The 20-round probes run all rounds at once, every round with its OWN user: Firefly's PUT rewrites the user's
 * `lastActivity` preference row inside its transaction, a hot row that is not what these tests are about.
 *
 * @internal
 *
 * @coversNothing
 */
final class PairConcurrencyTest extends PairTestCase
{
    private const int ROUNDS = 20;

    /** @var array<int, string> */
    private array $files = [];

    public function testTwoSimultaneousMergesOfTheSamePairAreOneMergeAndTheLoserGetsTheSameId(): void
    {
        $this->raceRounds(
            fn (array $r, int $i): array => ['keep' => $this->fastSingle(['user' => $r['user'], 'type' => 'withdrawal', 'account' => $r['a'], 'plaid' => 'OUT-'.$i]), 'abs' => $this->fastSingle(['user' => $r['user'], 'type' => 'deposit', 'account' => $r['b'], 'plaid' => 'IN-'.$i])],
            fn (array $c): array => [fn (): array => $this->doMerge($c['user'], $c['keep'], $c['abs']), fn (): array => $this->doMerge($c['user'], $c['keep'], $c['abs'])],
            function (int $i, array $c, array $out): void {
                $this->assertSame([200, 200], array_column($out, 'status'), 'round '.$i.' '.json_encode($out));
                $this->assertSame($out[0]['id'], $out[1]['id'], 'round '.$i);
                $this->assertSame(1, PairMerge::where('keep_journal_id', $c['keep']['journal'])->whereNull('unmerged_at')->count(), 'round '.$i);
                $this->assertSame(['IN-'.$i => 'destination', 'OUT-'.$i => 'source'], $this->linkLegs($c['keep']['journal']));
                $this->assertSame(2, DB::table('plaid_transaction_links')->whereIn('plaid_transaction_id', ['OUT-'.$i, 'IN-'.$i])->count());
                $this->assertNotNull(DB::table('transaction_journals')->where('id', $c['abs']['journal'])->value('deleted_at'));
            },
        );
    }

    /** The first merge holds every lock and sleeps; the second must wait, then answer the idempotent 200. */
    public function testSecondMergeOfTheSamePairWaitsForTheFirstAndIsIdempotent(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $held         = $this->flag();
        $out          = $this->parallel([
            fn (): array => $this->mergeHolding($keep, $abs, $held, 600),
            fn (): array => $this->after($held, fn (): array => $this->doMerge($this->user, $keep, $abs)),
        ], ['advisory']);
        $this->assertSame([200, 200], array_column($out, 'status'), json_encode($out));
        $this->assertSame($out[0]['id'], $out[1]['id']);
        $this->assertSame(1, PairMerge::count());
    }

    /** Two merges that share one journal (the same keep): exactly one wins, the other is a 409. */
    public function testTwoMergesSharingAJournalOneWinsTheOtherIs409(): void
    {
        $this->raceRounds(
            fn (array $r, int $i): array => [
                'keep' => $this->fastSingle(['user' => $r['user'], 'type' => 'withdrawal', 'account' => $r['a'], 'plaid' => 'K-'.$i]),
                'abs1' => $this->fastSingle(['user' => $r['user'], 'type' => 'deposit', 'account' => $r['b'], 'plaid' => 'D1-'.$i]),
                'abs2' => $this->fastSingle(['user' => $r['user'], 'type' => 'deposit', 'account' => $r['l'], 'plaid' => 'D2-'.$i]),
            ],
            fn (array $c): array => [fn (): array => $this->doMerge($c['user'], $c['keep'], $c['abs1']), fn (): array => $this->doMerge($c['user'], $c['keep'], $c['abs2'])],
            function (int $i, array $c, array $out): void {
                $statuses = array_column($out, 'status');
                sort($statuses);
                $this->assertSame([200, 409], $statuses, 'round '.$i.' '.json_encode($out));
                $firstWon = 200 === $out[0]['status'];
                $this->assertContains($out[$firstWon ? 1 : 0]['reason'], ['not_single', 'link_conflict', 'stale'], 'round '.$i);
                $this->assertSame(1, PairMerge::where('keep_journal_id', $c['keep']['journal'])->whereNull('unmerged_at')->count(), 'round '.$i);
                $this->assertSame(2, DB::table('plaid_transaction_links')->where('transaction_journal_id', $c['keep']['journal'])->count(), 'keep has its own link and the winner\'s');
                $winner = $firstWon ? $c['abs1'] : $c['abs2'];
                $loser  = $firstWon ? $c['abs2'] : $c['abs1'];
                $this->assertNotNull(DB::table('transaction_journals')->where('id', $winner['journal'])->value('deleted_at'));
                $this->assertNull(DB::table('transaction_journals')->where('id', $loser['journal'])->value('deleted_at'));
                $this->assertCount(1, $this->linkLegs($loser['journal']));
            },
        );
    }

    /** The same, but the shared journal is the absorbed one. */
    public function testTwoMergesSharingTheAbsorbedJournalOneWinsTheOtherIs409(): void
    {
        $this->raceRounds(
            fn (array $r, int $i): array => [
                'abs'   => $this->fastSingle(['user' => $r['user'], 'type' => 'deposit', 'account' => $r['b'], 'plaid' => 'D-'.$i]),
                'keep1' => $this->fastSingle(['user' => $r['user'], 'type' => 'withdrawal', 'account' => $r['a'], 'plaid' => 'K1-'.$i]),
                'keep2' => $this->fastSingle(['user' => $r['user'], 'type' => 'withdrawal', 'account' => $r['l'], 'plaid' => 'K2-'.$i]),
            ],
            fn (array $c): array => [fn (): array => $this->doMerge($c['user'], $c['keep1'], $c['abs']), fn (): array => $this->doMerge($c['user'], $c['keep2'], $c['abs'])],
            function (int $i, array $c, array $out): void {
                $statuses = array_column($out, 'status');
                sort($statuses);
                $this->assertSame([200, 409], $statuses, 'round '.$i.' '.json_encode($out));
                $this->assertSame(1, PairMerge::where('absorbed_journal_id', $c['abs']['journal'])->whereNull('unmerged_at')->count());
                $this->assertSame(1, DB::table('plaid_transaction_links')->where('plaid_transaction_id', 'D-'.$i)->count());
                $this->assertSame(1, DB::table('transaction_journals')->where('id', $c['abs']['journal'])->whereNotNull('deleted_at')->count());
                $firstWon = 200 === $out[0]['status'];
                $loser    = $firstWon ? $c['keep2'] : $c['keep1'];
                $this->assertSame([($firstWon ? 'K2-' : 'K1-').$i => 'single'], $this->linkLegs($loser['journal']));
            },
        );
    }

    /** A second merge that arrives while the first one holds the locks must queue, then be refused (the journal is no longer single). */
    public function testSharedJournalLoserWaitsAndIsRefused(): void
    {
        $keep = $this->single(['type' => 'withdrawal', 'account' => $this->assetA, 'plaid' => 'K']);
        $abs1 = $this->single(['type' => 'deposit', 'account' => $this->assetB, 'plaid' => 'D1']);
        $abs2 = $this->single(['type' => 'deposit', 'account' => $this->loanA, 'plaid' => 'D2']);
        $held = $this->flag();
        $out  = $this->parallel([
            fn (): array => $this->mergeHolding($keep, $abs1, $held, 600),
            fn (): array => $this->after($held, fn (): array => $this->doMerge($this->user, $keep, $abs2)),
        ], ['advisory']);
        $this->assertSame([200, 409], array_column($out, 'status'), json_encode($out));
        // the loser queued at the advisory locks of ITS link set; when it got them, keep had gained the winner's link
        $this->assertSame('link_conflict', $out[1]['reason']);
        $this->assertSame(1, $this->liveMerges());
        $this->assertSame(['D2' => 'single'], $this->linkLegs($abs2['journal']));
    }

    /** A merge racing a PUT of the same journal: either order leaves a consistent state, and the edit is never lost. */
    public function testMergeRacingAPutNeverLosesTheEdit(): void
    {
        $this->raceRounds(
            fn (array $r, int $i): array => ['keep' => $this->fastSingle(['user' => $r['user'], 'type' => 'withdrawal', 'account' => $r['a'], 'plaid' => 'OUT-'.$i]), 'abs' => $this->fastSingle(['user' => $r['user'], 'type' => 'deposit', 'account' => $r['b'], 'plaid' => 'IN-'.$i])],
            fn (array $c): array => [fn (): array => $this->doMerge($c['user'], $c['keep'], $c['abs']), fn (): array => $this->doPut($c['user'], $c['keep'], 'edited')],
            function (int $i, array $c, array $out): void {
                $this->assertSame(200, $out[1]['status'], 'the PUT must succeed, round '.$i.' '.json_encode($out));
                $this->assertContains($out[0]['status'], [200, 409], 'round '.$i.' '.json_encode($out));
                $this->assertSame('edited', TransactionJournal::find($c['keep']['journal'])->description, 'the edit is never lost, round '.$i);
                $live = PairMerge::where('keep_journal_id', $c['keep']['journal'])->whereNull('unmerged_at')->count();
                if (200 === $out[0]['status']) {
                    $this->assertSame(1, $live);
                    $this->assertSame(['IN-'.$i => 'destination', 'OUT-'.$i => 'source'], $this->linkLegs($c['keep']['journal']));
                    $this->assertSame('Transfer', TransactionJournal::with('transactionType')->find($c['keep']['journal'])->transactionType->type);

                    return;
                }
                $this->assertSame('stale', $out[0]['reason'], 'round '.$i);
                $this->assertSame(0, $live);
                $this->assertSame(['OUT-'.$i => 'single'], $this->linkLegs($c['keep']['journal']));
                $this->assertSame(['IN-'.$i => 'single'], $this->linkLegs($c['abs']['journal']));
            },
        );
    }

    /** Deterministic: the PUT holds the journal row, the merge arrives, must wait, and then is stale. */
    public function testMergeQueuedBehindARunningPutIsStale(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $held         = $this->flag();
        $out          = $this->parallel([
            fn (): array => $this->putHolding($keep, 'edited', $held, 600),
            fn (): array => $this->after($held, fn (): array => $this->doMerge($this->user, $keep, $abs)),
        ], ['transactionid', 'tuple']);
        $this->assertSame([200, 409], array_column($out, 'status'), json_encode($out));
        $this->assertSame('stale', $out[1]['reason']);
        $this->assertSame('edited', TransactionJournal::find($keep['journal'])->description);
        $this->assertSame(0, PairMerge::count());
        $this->assertSame(['OUT1' => 'single'], $this->linkLegs($keep['journal']));
    }

    /** Deterministic: the merge holds its locks, the PUT arrives, must wait, then edits the MERGED journal. */
    public function testPutQueuedBehindARunningMergeEditsTheMergedJournal(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $held         = $this->flag();
        $out          = $this->parallel([
            fn (): array => $this->mergeHolding($keep, $abs, $held, 600),
            fn (): array => $this->after($held, fn (): array => $this->doPut($this->user, $keep, 'edited after')),
        ], ['transactionid', 'tuple']);
        $this->assertSame([200, 200], array_column($out, 'status'), json_encode($out));
        $this->assertSame('edited after', TransactionJournal::find($keep['journal'])->description);
        $this->assertSame('Transfer', TransactionJournal::with('transactionType')->find($keep['journal'])->transactionType->type);
        $this->assertSame(['IN1' => 'destination', 'OUT1' => 'source'], $this->linkLegs($keep['journal']));
        $this->assertSame(1, $this->liveMerges());
    }

    /** core-2 L2 (r3), kills the no-transactions-lock mutant: a UI reconcile (transactions row only, no journal lock) holds its row; a merge must queue on it and then be refused as reconciled. */
    public function testMergeQueuedBehindAReconcileOfKeepIsRefusedAsReconciled(): void
    {
        [$keep, $abs] = $this->pair($this->assetA, $this->assetB);
        $held         = $this->flag();
        $out          = $this->parallel([
            fn (): array => $this->reconcileHolding($keep, $held, 600),
            fn (): array => $this->after($held, fn (): array => $this->doMerge($this->user, $keep, $abs)),
        ], ['transactionid', 'tuple']);
        $this->assertSame(409, $out[1]['status'], json_encode($out));
        $this->assertSame('reconciled', $out[1]['reason'], json_encode($out));
        $this->assertSame(0, PairMerge::count());
    }

    /** core-2 N1 (r3): a REAL race for the PUT lock (r2 L1): PUT 2 queues behind PUT 1 and must bump from PUT 1's stamp, so the final stamp is two steps past the start. */
    public function testTwoQueuedPutsMoveTheStampTwoSteps(): void
    {
        [$keep] = $this->pair($this->assetA, $this->assetB);
        $start  = \Carbon\Carbon::now()->addSeconds(100)->startOfSecond();
        DB::table('transaction_groups')->where('id', $keep['group'])->update(['updated_at' => $start->toDateTimeString()]);
        DB::table('transaction_journals')->where('transaction_group_id', $keep['group'])->update(['updated_at' => $start->toDateTimeString()]);
        $held = $this->flag();
        $out  = $this->parallel([
            fn (): array => $this->putHolding($keep, 'first', $held, 600),
            fn (): array => $this->after($held, fn (): array => $this->doPut($this->user, $keep, 'second')),
        ]);
        $this->assertSame([200, 200], array_column($out, 'status'), json_encode($out));
        $this->assertSame('second', TransactionJournal::find($keep['journal'])->description);
        $this->assertSame($start->copy()->addSeconds(2)->toDateTimeString(), (string) DB::table('transaction_groups')->where('id', $keep['group'])->value('updated_at'));
    }

    /** Merges of unrelated pairs share no lock and both succeed. */
    public function testUnrelatedMergesRunInParallel(): void
    {
        [$k1, $a1] = $this->pair($this->assetA, $this->assetB, 'O1', 'I1');
        [$k2, $a2] = $this->pair($this->loanA, $this->loanB, 'O2', 'I2');
        $out       = $this->parallel([fn (): array => $this->doMerge($this->user, $k1, $a1), fn (): array => $this->doMerge($this->user, $k2, $a2)]);
        $this->assertSame([200, 200], array_column($out, 'status'), json_encode($out));
        $this->assertSame(2, $this->liveMerges());
    }

    /** Two unmerges of the same merge at once: one does the work, the other answers the same unmerged state. */
    public function testTwoSimultaneousUnmergesAreOneUnmerge(): void
    {
        $this->raceRounds(
            function (array $r, int $i): array {
                $keep = $this->fastSingle(['user' => $r['user'], 'type' => 'withdrawal', 'account' => $r['a'], 'plaid' => 'OUT-'.$i]);
                $abs  = $this->fastSingle(['user' => $r['user'], 'type' => 'deposit', 'account' => $r['b'], 'plaid' => 'IN-'.$i]);
                $this->actingAs($r['user'], 'api');
                $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');

                return ['keep' => $keep, 'abs' => $abs, 'id' => $id];
            },
            fn (array $c): array => [fn (): array => $this->doUnmerge($c['user'], $c['id']), fn (): array => $this->doUnmerge($c['user'], $c['id'])],
            function (int $i, array $c, array $out): void {
                $this->assertSame([200, 200], array_column($out, 'status'), 'round '.$i.' '.json_encode($out));
                $this->assertSame(['IN-'.$i => 'single'], $this->linkLegs($c['abs']['journal']));
                $this->assertSame(['OUT-'.$i => 'single'], $this->linkLegs($c['keep']['journal']));
                $this->assertNotNull(PairMerge::find($c['id'])->unmerged_at);
                $this->assertNull(DB::table('transaction_journals')->where('id', $c['abs']['journal'])->value('deleted_at'));
            },
        );
    }

    /** core-3 L5: unmerge racing a PUT of keep, with the journal stamp ahead of its group (the state that hid review R1 H1). */
    public function testUnmergeRacingAPutKeepsTheEditAndRestoresTheLinks(): void
    {
        $this->raceRounds(
            function (array $r, int $i): array {
                $keep = $this->fastSingle(['user' => $r['user'], 'type' => 'withdrawal', 'account' => $r['a'], 'plaid' => 'OUT-'.$i, 'journalAhead' => true]);
                $abs  = $this->fastSingle(['user' => $r['user'], 'type' => 'deposit', 'account' => $r['b'], 'plaid' => 'IN-'.$i]);
                $this->actingAs($r['user'], 'api');
                $id = $this->merge($keep, $abs)->assertOk()->json('data.pair_merge_id');

                return ['keep' => $keep, 'abs' => $abs, 'id' => $id];
            },
            fn (array $c): array => [fn (): array => $this->doUnmerge($c['user'], $c['id']), fn (): array => $this->doPut($c['user'], $c['keep'], 'edited')],
            function (int $i, array $c, array $out): void {
                $this->assertSame([200, 200], array_column($out, 'status'), 'round '.$i.' '.json_encode($out));
                $this->assertSame('edited', TransactionJournal::find($c['keep']['journal'])->description, 'the edit is never lost, round '.$i);
                $this->assertSame(['OUT-'.$i => 'single'], $this->linkLegs($c['keep']['journal']));
                $this->assertSame(['IN-'.$i => 'single'], $this->linkLegs($c['abs']['journal']));
                $this->assertNotNull(PairMerge::find($c['id'])->unmerged_at);
                $this->assertSame(
                    DB::table('transaction_journals')->where('id', $c['keep']['journal'])->value('updated_at'),
                    DB::table('transaction_groups')->where('id', $c['keep']['group'])->value('updated_at'),
                    'one stamp for journal and group after the race, round '.$i,
                );
            },
        );
    }

    #[Override]
    public function beginDatabaseTransaction(): void
    {
        // real commits: the children must see what the parent prepared.
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('real-connection concurrency tests need Postgres (advisory locks, row locks)');
        }
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required to fork the competing requests');
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        // everything this class committed is removed again, so no other test sees it. Seeded reference tables are not touched.
        if ('pgsql' === DB::connection()->getDriverName()) {
            // TRUNCATE ... CASCADE is only ever allowed in the throwaway database scripts/test-pgsql.sh creates
            if ('firefly' !== DB::connection()->getDatabaseName() || !str_starts_with((string) config('database.connections.pgsql.host'), 'plaid-pgtest-')) {
                throw new \LogicException('refusing to TRUNCATE in a database that is not the throwaway test stack');
            }
            DB::statement('TRUNCATE users, user_groups, notes, locations, audit_log_entries RESTART IDENTITY CASCADE');
        }
        parent::tearDown();
    }

    // ----------------------------------------------------------------------------------------------- the harness

    /**
     * ROUNDS independent races at once, every round with its own user and journals, all children behind one gate.
     * (One sequential round costs about half a second; this keeps 20 rounds well inside the 2 minute rule.)
     *
     * @param callable(array<string, mixed>, int):array<string, mixed>                   $prepare data of one round, gets the round's actors
     * @param callable(array<string, mixed>):array<int, callable>                        $jobs    the competing requests of one round
     * @param callable(int, array<string, mixed>, array<int, array<string, mixed>>):void $judge
     */
    private function raceRounds(callable $prepare, callable $jobs, callable $judge): void
    {
        $contexts = [];
        $all      = [];
        $owner    = [];
        for ($i = 1; $i <= self::ROUNDS; ++$i) {
            $actors       = $this->actors($i);
            $contexts[$i] = $prepare($actors, $i) + ['user' => $actors['user']];
            foreach ($jobs($contexts[$i]) as $job) {
                $all[]   = $job;
                $owner[] = $i;
            }
        }
        $results = $this->parallel($all);
        $byRound = [];
        foreach ($results as $index => $result) {
            $byRound[$owner[$index]][] = $result;
        }
        $this->actingAs($this->user, 'api');
        foreach ($byRound as $round => $out) {
            $judge($round, $contexts[$round], $out);
        }
    }

    /** @return array{user: User, a: Account, b: Account, l: Account} a fresh user (own preferences row) with its accounts */
    private function actors(int $round): array
    {
        $user    = $this->otherUser(sprintf('race%d-%d@email.com', $round, random_int(1000, 999999)));
        // one request now, so the user's default currency row exists: Firefly creates it lazily on a user's first request,
        // and two simultaneous FIRST requests of one user collide on it (unrelated to the pair merge).
        $this->actingAs($user, 'api');
        $this->getJson(route('api.v1.plaid-links.index', ['plaid_transaction_id' => ['warm-up']]))->assertOk();
        $account = static fn (AccountTypeEnum $type, string $name): Account => Account::factory()->for($user)->withType($type)->create(['name' => $name, 'user_group_id' => $user->user_group_id]);

        return ['user' => $user, 'a' => $account(AccountTypeEnum::ASSET, 'A'), 'b' => $account(AccountTypeEnum::ASSET, 'B'), 'l' => $account(AccountTypeEnum::LOAN, 'L')];
    }

    private function flag(): string
    {
        $file = sys_get_temp_dir().'/pair-flag-'.getmypid().'-'.count($this->files);
        @unlink($file);
        $this->files[] = $file;

        return $file;
    }

    /**
     * Run every job in its own forked process with its own connection. Returns the jobs' results in order.
     * The queue check proves that SOME backend waited on a lock of that kind, not which one: unambiguous with two children. With a third
     * connection (a poller, a listener), name each child's application_name and filter on it.
     *
     * @param array<int, callable():array<string,mixed>> $jobs
     * @param array<int, string>                         $expectQueue pg_stat_activity wait_event names (Lock type), at least one of which the parent must SEE
     *                                                                 while the jobs run: proof that the second request really queued on that kind of lock,
     *                                                                 with no timing threshold. 'transactionid'/'tuple' = a row lock, 'advisory' = an advisory lock
     *
     * @return array<int, array<string, mixed>>
     */
    private function parallel(array $jobs, array $expectQueue = []): array
    {
        $go      = $this->flag();
        // close the parent's connection BEFORE forking: a PDO shared by two processes interleaves their protocol messages.
        // Each child opens its own on first use, the parent reconnects lazily afterwards.
        DB::disconnect();
        $outputs = [];
        $pids    = [];
        foreach ($jobs as $index => $job) {
            $outputs[$index] = $this->flag();
            $pid             = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');
            if (0 === $pid) {
                $this->runChild($job, $go, $outputs[$index]);
            }
            $pids[$index] = $pid;
        }
        usleep(300000); // every child is forked and waits at the gate
        touch($go);
        $deadline = microtime(true) + 60;
        $seen     = [];
        foreach ($pids as $pid) {
            while (0 === pcntl_waitpid($pid, $status, WNOHANG)) {
                if ([] !== $expectQueue && [] === array_intersect($seen, $expectQueue)) {
                    // the parent reconnects lazily on its own socket; the children each opened theirs
                    foreach (DB::select("select wait_event from pg_stat_activity where wait_event_type = 'Lock' and datname = current_database()") as $row) {
                        $seen[] = (string) $row->wait_event;
                    }
                }
                $this->assertLessThan($deadline, microtime(true), 'a child did not finish: deadlock or lost wake-up');
                usleep(5000);
            }
        }
        if ([] !== $expectQueue) {
            $this->assertNotSame([], array_intersect($seen, $expectQueue), 'no backend was seen waiting on '.implode('/', $expectQueue).' (saw: '.implode(',', array_unique($seen)).'): the second request did not queue');
        }
        $results = [];
        foreach ($outputs as $index => $file) {
            $raw = is_file($file) ? file_get_contents($file) : false;
            $this->assertNotFalse($raw, 'child '.$index.' wrote no result');
            $results[$index] = json_decode((string) $raw, true);
            $this->assertIsArray($results[$index], 'child '.$index.' result: '.$raw);
            $this->assertArrayNotHasKey('crash', $results[$index], (string) ($results[$index]['crash'] ?? ''));
        }

        return $results;
    }

    /** Never returns: the child reports and kills itself so no destructor closes the parent's socket. */
    private function runChild(callable $job, string $go, string $output): never
    {
        try {
            while (!is_file($go)) {
                usleep(500);
            }
            $result = $job();
        } catch (\Throwable $e) {
            $result = ['crash' => get_class($e).': '.$e->getMessage()];
        }
        file_put_contents($output, json_encode($result));
        posix_kill(posix_getpid(), SIGKILL);

        exit(0);
    }

    // ----------------------------------------------------------------------------------------------- the requests

    /** @return array<string, mixed> */
    private function doMerge(User $as, array $keep, array $abs, ?callable $afterLocks = null): array
    {
        $this->actingAs($as, 'api');
        $started = $this->nowMs();
        if (null !== $afterLocks) {
            DB::listen(static function ($query) use ($afterLocks): void {
                // the journal row lock query of lock() is the moment every lock of the merge is held (not a later statement
                // that merely writes rows: those writes would queue a PUT even if lock() took no row locks)
                static $done = false;
                if (!$done && str_starts_with($query->sql, 'select "id" from "transaction_journals" where "id" in') && str_contains($query->sql, 'for update')) {
                    $done = true;
                    $afterLocks();
                }
            });
        }
        $response = $this->merge($keep, $abs);

        return ['status' => $response->getStatusCode(), 'id' => $response->json('data.pair_merge_id'), 'reason' => $response->json('reason'), 'started_ms' => $started, 'finished_ms' => $this->nowMs()];
    }

    /** A merge that, once it holds its locks, tells the others and sleeps. */
    private function mergeHolding(array $keep, array $abs, string $flag, int $sleepMs): array
    {
        return $this->doMerge($this->user, $keep, $abs, static function () use ($flag, $sleepMs): void {
            touch($flag);
            usleep($sleepMs * 1000);
        });
    }

    /** Wait for the first child to hold its locks, then run the job. */
    private function after(string $flag, callable $job): array
    {
        $deadline = microtime(true) + 20;
        while (!is_file($flag) && microtime(true) < $deadline) {
            usleep(1000);
        }
        usleep(50000);

        return $job();
    }

    /** @return array<string, mixed> */
    private function doPut(User $as, array $keep, string $description, ?callable $afterUpdate = null): array
    {
        $this->actingAs($as, 'api');
        $started = $this->nowMs();
        if (null !== $afterUpdate) {
            DB::listen(static function ($query) use ($afterUpdate): void {
                static $done = false;
                if (!$done && str_starts_with($query->sql, 'update "transaction_journals" set "description"')) {
                    $done = true;
                    $afterUpdate();
                }
            });
        }
        $response = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $keep['group']]), ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [['description' => $description]]]);

        return ['status' => $response->getStatusCode(), 'id' => null, 'reason' => $response->json('reason'), 'started_ms' => $started, 'finished_ms' => $this->nowMs()];
    }

    /** A PUT that holds the journal row (its transaction is open) after its first write, and sleeps. */
    private function putHolding(array $keep, string $description, string $flag, int $sleepMs): array
    {
        return $this->doPut($this->user, $keep, $description, static function () use ($flag, $sleepMs): void {
            touch($flag);
            usleep($sleepMs * 1000);
        });
    }

    private function reconcileHolding(array $keep, string $flag, int $sleepMs): array
    {
        DB::transaction(static function () use ($keep, $flag, $sleepMs): void {
            DB::table('transactions')->where('transaction_journal_id', $keep['journal'])->update(['reconciled' => true]);
            touch($flag);
            usleep($sleepMs * 1000);
        });

        return ['status' => 200, 'id' => null, 'reason' => null];
    }

    /** @return array<string, mixed> */
    private function doUnmerge(User $as, string $id): array
    {
        $this->actingAs($as, 'api');
        $response = $this->unmerge($id);

        return ['status' => $response->getStatusCode(), 'id' => $response->json('data.pair_merge_id'), 'reason' => $response->json('reason')];
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
