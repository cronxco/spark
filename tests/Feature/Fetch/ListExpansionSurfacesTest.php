<?php

namespace Tests\Feature\Fetch;

use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Jobs\Fetch\ExpandLinkListJob;
use App\Jobs\Fetch\FetchScheduledUrls;
use App\Mcp\Tools\GetSavedBookmarksTool;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Fetch\Assessment\ListPageDetector;
use App\Services\Fetch\BookmarkCreator;
use App\Services\Fetch\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Fixtures\FakeJev;
use Tests\Fixtures\FetchPages;
use Tests\TestCase;

class ListExpansionSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sleep::fake();
        config(['fetch.list_detection.enabled' => true, 'fetch.list_detection.shadow' => false]);

        $this->user = User::factory()->create();
        $group = IntegrationGroup::create(['user_id' => $this->user->id, 'service' => 'fetch', 'auth_metadata' => ['domains' => []]]);
        $this->integration = Integration::create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
            'instance_type' => 'fetcher',
            'name' => 'Fetch',
            'integration_group_id' => $group->id,
            'configuration' => [],
        ]);
    }

    #[Test]
    public function a_captured_list_page_is_expanded(): void
    {
        FakeJev::fake(['page_kind' => 'article_list', 'has_article_list' => 0.9, 'cluster_c0_is_primary' => 0.9, 'link_*_is_article' => 0.9, 'link_*_role' => 'article']);
        Sanctum::actingAs($this->user, ['bookmark:write']);

        $this->postJson('/api/v1/bookmarks/capture', [
            'url' => 'https://example.com/',
            'html' => FetchPages::blogIndex(),
            'title' => 'Captured blog',
        ])
            ->assertCreated()
            ->assertJsonPath('state', 'list_expanded')
            ->assertJsonPath('items_found', 8)
            ->assertJsonPath('bookmark.title', 'Captured blog');

        $bookmark = EventObject::where('url', 'https://example.com/')->sole();
        $this->assertSame('browser_extension', $bookmark->metadata['subscription_source']);
        $this->assertFalse($bookmark->metadata['enabled']);
        $this->assertSame(1, $bookmark->metadata['fetch_count']);
        $this->assertSame('list', $bookmark->metadata['list_detection']['kind']);
        Queue::assertPushed(ExpandLinkListJob::class, fn (ExpandLinkListJob $job): bool => count($job->items) === 8);
        Queue::assertNotPushed(ProcessFetchedContent::class);
    }

    #[Test]
    public function capturing_an_existing_recurring_list_preserves_its_subscription(): void
    {
        FakeJev::fake(['page_kind' => 'article_list', 'has_article_list' => 0.9,
            'cluster_c0_is_primary' => 0.9, 'link_*_is_article' => 0.9, 'link_*_role' => 'article']);
        $bookmark = $this->scheduledBookmark('https://example.com/', 'recurring', null,
            ['subscription_source' => 'subscribed']);
        Sanctum::actingAs($this->user, ['bookmark:write']);

        $this->postJson('/api/v1/bookmarks/capture', ['url' => $bookmark->url,
            'html' => FetchPages::blogIndex(), 'title' => 'Captured blog'])
            ->assertOk()->assertJsonPath('state', 'list_expanded');

        $this->assertTrue($bookmark->fresh()->metadata['enabled']);
        $this->assertSame('subscribed', $bookmark->fresh()->metadata['subscription_source']);
        $this->assertSame('recurring', $bookmark->fresh()->metadata['fetch_mode']);
    }

    #[Test]
    public function a_captured_page_that_is_not_a_list_still_fails_extraction_without_a_bookmark(): void
    {
        FakeJev::fake(['page_kind' => 'other', 'has_article_list' => 0.1]);
        Sanctum::actingAs($this->user, ['bookmark:write']);

        $html = '<html><head><title>Tiny</title></head><body>'
            . implode('', array_map(fn (int $i): string => "<div class=\"card\"><a href=\"/p/item-{$i}\">An item called number {$i}</a></div>", range(1, 6)))
            . '</body></html>';

        $this->postJson('/api/v1/bookmarks/capture', ['url' => 'https://example.com/tiny', 'html' => $html])
            ->assertUnprocessable();

        $this->assertSame(0, EventObject::where('type', 'fetch_webpage')->count());
    }

    #[Test]
    public function the_scheduler_skips_one_time_bookmarks_whose_fetch_was_just_dispatched(): void
    {
        $recent = $this->scheduledBookmark('https://example.com/recent', 'once', now()->subMinutes(5));
        $stale = $this->scheduledBookmark('https://example.com/stale', 'once', now()->subHour());
        $never = $this->scheduledBookmark('https://example.com/never', 'once', null);
        $recurring = $this->scheduledBookmark('https://example.com/recurring', 'recurring', now()->subMinutes(5));

        $method = new ReflectionMethod(FetchScheduledUrls::class, 'fetchData');
        $ids = collect($method->invoke(new FetchScheduledUrls($this->integration))['webpages'])->pluck('id')->map(fn ($id): string => (string) $id);

        $this->assertFalse($ids->contains((string) $recent->id));
        $this->assertTrue($ids->contains((string) $stale->id));
        $this->assertTrue($ids->contains((string) $never->id));
        $this->assertTrue($ids->contains((string) $recurring->id));
    }

    #[Test]
    public function the_user_can_change_a_bookmarks_list_detection_mode(): void
    {
        $bookmark = $this->scheduledBookmark('https://example.com/list', 'recurring', null, ['list_detection' => ['kind' => 'article', 'assessed_at' => now()->toIso8601String()]]);
        $this->actingAs($this->user);

        Volt::test('bookmarks.index')->call('setListDetectionMode', (string) $bookmark->id, 'force');

        $memo = $bookmark->fresh()->metadata['list_detection'];
        $this->assertSame('force', $memo['mode']);
        $this->assertArrayNotHasKey('assessed_at', $memo);

        Volt::test('bookmarks.index')->call('setListDetectionMode', (string) $bookmark->id, 'bogus');
        $this->assertSame('force', $bookmark->fresh()->metadata['list_detection']['mode']);
    }

    #[Test]
    public function subscribing_to_a_discovered_tracking_variant_promotes_the_same_bookmark(): void
    {
        $this->mock(UrlSafetyValidator::class, fn ($mock) => $mock->shouldReceive('isSafe')->andReturnTrue());
        $bookmark = app(BookmarkCreator::class)->firstOrCreate($this->user->id,
            'https://example.com/post', [], ['subscription_source' => 'discovered',
                'fetch_count' => 3, 'enabled' => false, 'via' => 'list_expansion',
                'found_in' => 'list_expansion', 'list_expansion_depth' => 1])['bookmark'];
        $this->actingAs($this->user);

        Volt::test('bookmarks.index')->set('newUrl', $bookmark->url . '?utm_source=share')->call('subscribeToUrl')->assertHasNoErrors();

        $bookmark->refresh();
        $this->assertSame(1, EventObject::where('type', 'fetch_webpage')->count());
        $this->assertSame('subscribed', $bookmark->metadata['subscription_source']);
        $this->assertSame('recurring', $bookmark->metadata['fetch_mode']);
        $this->assertTrue($bookmark->metadata['enabled']);
        $this->assertSame(3, $bookmark->metadata['fetch_count']);
        $this->assertTrue(ListPageDetector::isEligibleForListExpansion($bookmark));
        $this->assertSame(1, $bookmark->metadata['discovery_origin']['list_expansion_depth']);
    }

    #[Test]
    public function the_user_cannot_change_someone_elses_bookmark(): void
    {
        $bookmark = $this->scheduledBookmark('https://example.com/list', 'recurring', null);
        $this->actingAs(User::factory()->create());

        Volt::test('bookmarks.index')->call('setListDetectionMode', (string) $bookmark->id, 'off');

        $this->assertArrayNotHasKey('list_detection', $bookmark->fresh()->metadata);
    }

    #[Test]
    public function the_urls_tab_shows_list_bookmarks(): void
    {
        $this->scheduledBookmark('https://example.com/list', 'recurring', null, ['list_detection' => ['kind' => 'list', 'shadow' => false, 'last_new_count' => 3]]);

        $this->actingAs($this->user)->get('/bookmarks?tab=urls')
            ->assertOk()
            ->assertSee('List · 3 new')
            ->assertSee('Always treat as one article');
    }

    #[Test]
    public function saved_bookmarks_describe_list_pages(): void
    {
        $list = $this->scheduledBookmark('https://example.com/list', 'recurring', null, ['list_detection' => ['kind' => 'list', 'shadow' => false, 'last_new_count' => 3, 'expanded_at' => '2026-09-27T10:00:00+00:00']]);
        $shadow = $this->scheduledBookmark('https://example.com/shadow', 'recurring', null, ['list_detection' => ['kind' => 'list', 'shadow' => true]]);
        $method = new ReflectionMethod(GetSavedBookmarksTool::class, 'listDetails');
        $tool = app(GetSavedBookmarksTool::class);

        $this->assertSame(['kind' => 'list', 'last_new_articles' => 3, 'expanded_at' => '2026-09-27T10:00:00+00:00'], $method->invoke($tool, $list));
        $this->assertSame([], $method->invoke($tool, $shadow));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function scheduledBookmark(string $url, string $mode, $dispatchedAt, array $extra = []): EventObject
    {
        return EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => $url,
            'url' => $url,
            'time' => now(),
            'metadata' => array_merge([
                'fetch_integration_id' => $this->integration->id,
                'fetch_mode' => $mode,
                'enabled' => true,
                'fetch_count' => 0,
                'subscription_source' => 'subscribed',
                'fetch_dispatched_at' => $dispatchedAt?->toIso8601String(),
            ], $extra),
        ]);
    }
}
