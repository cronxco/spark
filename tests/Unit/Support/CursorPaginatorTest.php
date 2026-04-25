<?php

namespace Tests\Unit\Support;

use App\Models\Event;
use App\Models\Integration;
use App\Support\CursorPaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CursorPaginatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function encode_and_decode_round_trip(): void
    {
        $encoded = CursorPaginator::encode('evt_123', '2026-04-18 10:00:00.000000');

        $this->assertSame(['evt_123', '2026-04-18 10:00:00.000000'], CursorPaginator::decode($encoded));
    }

    #[Test]
    public function decode_returns_null_for_malformed_cursor(): void
    {
        $this->assertNull(CursorPaginator::decode('not-base64!!!'));
        $this->assertNull(CursorPaginator::decode(base64_encode('missing-pipe')));
    }

    #[Test]
    public function paginate_returns_first_page_and_next_cursor(): void
    {
        $integration = Integration::factory()->create();

        // Seed 7 events with strictly increasing created_at so ordering is deterministic.
        for ($i = 0; $i < 7; $i++) {
            Event::factory()->create([
                'integration_id' => $integration->id,
                'created_at' => now()->subMinutes(10 - $i),
            ]);
        }

        [$page, $cursor, $hasMore] = CursorPaginator::paginate(
            Event::query()->where('integration_id', $integration->id),
            cursor: null,
            limit: 3,
        );

        $this->assertCount(3, $page);
        $this->assertNotNull($cursor);
        $this->assertTrue($hasMore);
    }

    #[Test]
    public function paginate_exhausts_result_set_with_null_next_cursor(): void
    {
        $integration = Integration::factory()->create();
        for ($i = 0; $i < 2; $i++) {
            Event::factory()->create([
                'integration_id' => $integration->id,
                'created_at' => now()->subMinutes(5 - $i),
            ]);
        }

        [$page, $cursor, $hasMore] = CursorPaginator::paginate(
            Event::query()->where('integration_id', $integration->id),
            cursor: null,
            limit: 5,
        );

        $this->assertCount(2, $page);
        $this->assertNull($cursor);
        $this->assertFalse($hasMore);
    }

    #[Test]
    public function paginate_walks_through_all_pages(): void
    {
        $integration = Integration::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            Event::factory()->create([
                'integration_id' => $integration->id,
                'created_at' => now()->subMinutes(10 - $i),
            ]);
        }

        [$firstPage, $cursor, $hasMore] = CursorPaginator::paginate(
            Event::query()->where('integration_id', $integration->id),
            cursor: null,
            limit: 2,
        );

        $this->assertCount(2, $firstPage);
        $this->assertTrue($hasMore);

        [$secondPage, $cursor, $hasMore] = CursorPaginator::paginate(
            Event::query()->where('integration_id', $integration->id),
            cursor: $cursor,
            limit: 2,
        );

        $this->assertCount(2, $secondPage);
        $this->assertTrue($hasMore);

        [$thirdPage, $cursor, $hasMore] = CursorPaginator::paginate(
            Event::query()->where('integration_id', $integration->id),
            cursor: $cursor,
            limit: 2,
        );

        $this->assertCount(1, $thirdPage);
        $this->assertFalse($hasMore);
        $this->assertNull($cursor);

        // No duplicates across pages.
        $seenIds = collect([$firstPage, $secondPage, $thirdPage])->flatten()->pluck('id');
        $this->assertSame(5, $seenIds->unique()->count());
    }
}
