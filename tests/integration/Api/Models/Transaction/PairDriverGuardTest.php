<?php

/*
 * PairDriverGuardTest.php
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

use FireflyIII\Models\PairMerge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Override;
use Tests\integration\TestCase;

/**
 * Pair merges need PostgreSQL; on any other driver the endpoints answer 501 before reading anything.
 * Skips on Postgres (every pair test covers that side); runs in the default SQLite suite.
 *
 * @internal
 *
 * @coversNothing
 */
final class PairDriverGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        if ('pgsql' === DB::connection()->getDriverName()) {
            $this->markTestSkipped('the guard is for other drivers; every pair test covers the Postgres side');
        }
        $this->actingAs($this->createAuthenticatedUser(), 'api');
    }

    public function testMergeAnswers501WithAReasonOffPostgres(): void
    {
        $res = $this->postJson(route('api.v1.plaid-links.pair.store'), [
            'keep_group_id' => 1, 'absorb_group_id' => 2,
            'keep_updated_at' => '2026-10-01T00:00:00Z', 'absorb_updated_at' => '2026-10-01T00:00:00Z',
        ]);
        $this->assertGuard($res);
    }

    public function testUnmergeAnswers501WithAReasonOffPostgres(): void
    {
        $this->assertGuard($this->deleteJson(route('api.v1.plaid-links.pair.destroy', ['pairMerge' => 1])));
    }

    public function testAnInvalidBodyIsStill501NotA422(): void
    {
        $this->assertGuard($this->postJson(route('api.v1.plaid-links.pair.store'), []));
    }

    private function assertGuard(\Illuminate\Testing\TestResponse $res): void
    {
        $res->assertStatus(501)->assertJsonPath('reason', 'unsupported_database')->assertJsonPath('driver', DB::connection()->getDriverName());
        $this->assertStringNotContainsString('SQLSTATE', (string) $res->getContent());
        $this->assertSame(0, PairMerge::count());
    }
}
