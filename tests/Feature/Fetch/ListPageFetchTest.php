<?php

namespace Tests\Feature\Fetch;

use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Jobs\Fetch\ExpandLinkListJob;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Fetch\BookmarkCreator;
use App\Services\Fetch\Links\LinkCandidateExtractor;
use App\Services\Fetch\Links\LinkClusterer;
use App\Services\Fetch\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\FakeJev;
use Tests\Fixtures\FetchPages;
use Tests\TestCase;

class ListPageFetchTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER = 'http://playwright.test';

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sleep::fake();
        $this->mock(UrlSafetyValidator::class, fn ($mock) => $mock->shouldReceive('isSafe')->andReturnTrue());

        config([
            'services.playwright.enabled' => true,
            'services.playwright.worker_url' => self::WORKER,
            'services.playwright.screenshot_enabled' => false,
            'fetch.list_detection.enabled' => true,
            'fetch.list_detection.shadow' => false,
        ]);

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
    public function a_list_page_is_expanded_instead_of_stored_as_an_article(): void
    {
        $this->worker(FetchPages::blogIndex(withSponsoredCard: true));
        $this->jevSaysList();
        $bookmark = $this->bookmark(['fetch_mode' => 'once', 'subscription_source' => 'api']);

        $this->fetch($bookmark);

        Queue::assertNotPushed(ProcessFetchedContent::class);
        Queue::assertPushed(ExpandLinkListJob::class, function (ExpandLinkListJob $job) use ($bookmark): bool {
            return $job->listBookmarkId === (string) $bookmark->id
                && count($job->items) === 8
                && ! collect($job->items)->contains(fn (array $item): bool => str_contains($item['url'], 'partner-content'))
                && $job->assessment['is_list'] === true;
        });

        $metadata = $bookmark->fresh()->metadata;
        $this->assertFalse($metadata['enabled']);
        $this->assertSame(1, $metadata['fetch_count']);
        $this->assertSame('completed', $metadata['discovery_status']);
        $this->assertNull($metadata['last_error']);
        $this->assertSame('list', $metadata['list_detection']['kind']);
        $this->assertSame('pending', $metadata['list_detection']['expansion_status']);
        $this->assertSame('list', end($metadata['playwright_history'])['content_kind']);
        $this->assertSame('The Example Blog — Latest posts', $bookmark->fresh()->title);
    }

    #[Test]
    public function a_recurring_list_stays_enabled_and_reassesses_links_next_time(): void
    {
        $this->worker(FetchPages::blogIndex(withSponsoredCard: true));
        $this->jevSaysList();
        $bookmark = $this->bookmark(['fetch_mode' => 'recurring', 'subscription_source' => 'subscribed']);

        $this->fetch($bookmark);
        $this->fetch($bookmark);

        $this->assertTrue($bookmark->fresh()->metadata['enabled']);
        $this->assertSame(2, $bookmark->fresh()->metadata['fetch_count']);
        Queue::assertPushed(ExpandLinkListJob::class, 2);
        Queue::assertPushed(ExpandLinkListJob::class, fn (ExpandLinkListJob $job): bool => count($job->items) === 8 && ! collect($job->items)->contains(fn (array $item): bool => str_contains($item['url'], 'partner-content')));
        Http::assertSentCount(6); // health + fetch + Jev on both scans
    }

    #[Test]
    public function jev_being_down_leaves_the_article_path_and_failure_count_alone(): void
    {
        $this->worker(FetchPages::articleWithRelatedRail());
        FakeJev::unavailable();
        $bookmark = $this->bookmark(['fetch_mode' => 'once', 'subscription_source' => 'api', 'list_detection' => ['mode' => 'force']]);

        $this->fetch($bookmark);

        Queue::assertNotPushed(ExpandLinkListJob::class);
        Queue::assertPushed(ProcessFetchedContent::class);
        $this->assertNull($bookmark->fresh()->metadata['last_error'] ?? null);
        $this->assertSame('unavailable', $bookmark->fresh()->metadata['list_detection']['last_status']);
    }

    #[Test]
    public function an_article_with_a_related_rail_never_reaches_jev(): void
    {
        $this->worker(FetchPages::articleWithRelatedRail());
        $this->jevSaysList();
        $bookmark = $this->bookmark(['fetch_mode' => 'once', 'subscription_source' => 'api']);

        $this->fetch($bookmark);

        Queue::assertPushed(ProcessFetchedContent::class);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === FakeJev::URL);
    }

    #[Test]
    public function shadow_mode_records_the_verdict_without_acting_on_it(): void
    {
        config(['fetch.list_detection.shadow' => true]);
        $this->worker(FetchPages::blogIndex());
        $this->jevSaysList();
        $bookmark = $this->bookmark(['fetch_mode' => 'once', 'subscription_source' => 'api']);

        $this->fetch($bookmark);

        Queue::assertNotPushed(ExpandLinkListJob::class);
        $memo = $bookmark->fresh()->metadata['list_detection'];
        $this->assertSame('list', $memo['kind']);
        $this->assertTrue($memo['shadow']);
        $this->assertSame('jev-1.13.0', $memo['model_version']);
    }

    #[Test]
    public function discovered_and_expanded_pages_are_never_assessed(): void
    {
        $this->worker(FetchPages::blogIndex());
        $this->jevSaysList();

        foreach ([['subscription_source' => 'discovered'], ['subscription_source' => 'discovered', 'found_in' => 'list_expansion', 'list_expansion_depth' => 1]] as $metadata) {
            $bookmark = $this->bookmark(['fetch_mode' => 'once'] + $metadata, 'https://blog.example.com/?v=' . count($metadata));
            $this->fetch($bookmark);
        }

        Queue::assertNotPushed(ExpandLinkListJob::class);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === FakeJev::URL);
    }

    #[Test]
    public function spotlight_and_legacy_bookmarks_are_eligible(): void
    {
        $this->worker(FetchPages::blogIndex());
        $this->jevSaysList();

        $this->fetch($this->bookmark(['fetch_mode' => 'recurring', 'added_via' => 'spotlight']));
        $this->fetch($this->bookmark([], 'https://blog.example.com/legacy'));

        Queue::assertPushed(ExpandLinkListJob::class, 2);
    }

    #[Test]
    public function the_off_mode_keeps_a_page_on_the_article_path(): void
    {
        $this->worker(FetchPages::blogIndex());
        $this->jevSaysList();

        $this->fetch($this->bookmark(['fetch_mode' => 'once', 'subscription_source' => 'api', 'list_detection' => ['mode' => 'off']]));

        Queue::assertNotPushed(ExpandLinkListJob::class);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === FakeJev::URL);
    }

    #[Test]
    public function nothing_happens_while_list_detection_is_disabled(): void
    {
        config(['fetch.list_detection.enabled' => false]);
        $this->worker(FetchPages::blogIndex());
        $this->jevSaysList();

        $this->fetch($this->bookmark(['fetch_mode' => 'once', 'subscription_source' => 'api']));

        Queue::assertNotPushed(ExpandLinkListJob::class);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === FakeJev::URL);
    }

    private function jevSaysList(): void
    {
        FakeJev::fake([
            'page_kind' => 'article_list',
            'has_article_list' => 0.95,
            'cluster_c0_is_primary' => 0.9,
            "link_{$this->sponsoredLinkId()}_is_article" => 0.05,
            'link_*_is_article' => 0.9, 'link_*_role' => 'article',
        ]);
    }

    /**
     * Candidate id of the sponsored card in the fixture's primary group.
     */
    private function sponsoredLinkId(): string
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(withSponsoredCard: true), 'https://blog.example.com/');

        foreach (LinkClusterer::fromConfig()->cluster($page)[0]->items as $candidate) {
            if (str_contains($candidate->url, 'partner-content')) {
                return $candidate->id;
            }
        }

        $this->fail('No sponsored card in the fixture');
    }

    private function worker(string $html): void
    {
        Http::fake([
            self::WORKER . '/health' => Http::response(['status' => 'ok', 'connected' => true]),
            self::WORKER . '/fetch' => Http::response([
                'html' => $html,
                'url' => 'https://blog.example.com/',
                'title' => 'Page',
                'screenshot' => null,
                'cookies' => [],
                'meta' => ['status' => 200],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function bookmark(array $metadata, string $url = 'https://blog.example.com/'): EventObject
    {
        return app(BookmarkCreator::class)->firstOrCreate($this->user->id, $url, [], array_merge([
            'fetch_integration_id' => $this->integration->id,
            'enabled' => true,
            'fetch_count' => 0,
        ], $metadata))['bookmark'];
    }

    private function fetch(EventObject $bookmark): void
    {
        (new FetchSingleUrl($this->integration, (string) $bookmark->id, $bookmark->url))->handle();
    }
}
