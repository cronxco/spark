<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OAuth\Karakeep\KarakeepBookmarksPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KarakeepBookmarkPaginationTest extends TestCase
{
    #[Test]
    public function follows_cursors_and_uses_supported_sort_parameters(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'karakeep.example/api/v1/users/me' => Http::response(['id' => 'user']),
            'karakeep.example/api/v1/bookmarks*' => Http::sequence()
                ->push(['bookmarks' => [['id' => 'one']], 'nextCursor' => 'second'])
                ->push(['bookmarks' => [['id' => 'two']], 'nextCursor' => null]),
            'karakeep.example/api/v1/tags' => Http::response(['tags' => []]),
            'karakeep.example/api/v1/lists' => Http::response(['lists' => []]),
        ]);

        $result = $this->job()->fetchForTest();

        $this->assertSame(['one', 'two'], array_column($result['bookmarks'], 'id'));
        Http::assertSent(fn ($request) => $request->url() === 'https://karakeep.example/api/v1/bookmarks?limit=10&sortOrder=desc');
        Http::assertSent(fn ($request) => $request->url() === 'https://karakeep.example/api/v1/bookmarks?limit=10&sortOrder=desc&cursor=second');
    }

    #[Test]
    public function does_not_return_partial_data_after_a_later_page_fails(): void
    {
        Http::fake([
            'karakeep.example/api/v1/users/me' => Http::response(['id' => 'user']),
            'karakeep.example/api/v1/bookmarks*' => Http::sequence()
                ->push(['bookmarks' => [['id' => 'one']], 'nextCursor' => 'second'])
                ->push([], 500),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to fetch Karakeep bookmarks: HTTP 500');
        $this->job()->fetchForTest();
    }

    #[Test]
    public function rejects_repeated_cursors_instead_of_looping_forever(): void
    {
        Http::fake([
            'karakeep.example/api/v1/users/me' => Http::response(['id' => 'user']),
            'karakeep.example/api/v1/bookmarks*' => Http::sequence()
                ->push(['bookmarks' => [], 'nextCursor' => 'same'])
                ->push(['bookmarks' => [], 'nextCursor' => 'same']),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid or repeated Karakeep bookmarks cursor');
        $this->job()->fetchForTest();
    }

    private function job(): KarakeepBookmarksPull
    {
        $group = new IntegrationGroup;
        $group->auth_metadata = ['api_url' => 'https://karakeep.example'];
        $group->access_token = 'test-token';

        $integration = new Integration;
        $integration->id = 'test-karakeep';
        $integration->service = 'karakeep';
        $integration->configuration = ['fetch_limit' => 10, 'sync_highlights' => false];
        $integration->setRelation('group', $group);

        return new class($integration) extends KarakeepBookmarksPull
        {
            public function fetchForTest(): array
            {
                return $this->fetchData();
            }
        };
    }
}
