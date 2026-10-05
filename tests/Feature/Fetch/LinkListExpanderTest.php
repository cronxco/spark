<?php

namespace Tests\Feature\Fetch;

use App\Jobs\Fetch\ExpandLinkListJob;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Fetch\BookmarkCreator;
use App\Services\Fetch\Expansion\ExpansionResult;
use App\Services\Fetch\Expansion\LinkListExpander;
use App\Services\Fetch\Expansion\LinkSeenStateProjector;
use App\Services\Fetch\Expansion\ListItem;
use App\Services\Fetch\FetchMetadata;
use App\Services\Fetch\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class LinkListExpanderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private EventObject $list;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['fetch.list_detection.enabled' => true, 'fetch.list_detection.shadow' => false]);
        $this->mock(UrlSafetyValidator::class, fn ($mock) => $mock->shouldReceive('isSafe')->andReturnTrue());

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
        $this->list = app(BookmarkCreator::class)->firstOrCreate($this->user->id, 'https://blog.example.com/', ['title' => 'The Example Blog'], [
            'fetch_mode' => 'recurring',
            'subscription_source' => 'subscribed',
            'fetch_integration_id' => $this->integration->id,
        ])['bookmark'];
    }

    #[Test]
    public function the_first_scan_records_everything_and_fetches_the_top_five(): void
    {
        $result = $this->expand($this->items(range(1, 12)));

        $this->assertTrue($result->coldStart);
        $this->assertSame(5, $result->countWithStatus(LinkListExpander::STATUS_QUEUED));
        $this->assertSame(7, $result->countWithStatus(LinkListExpander::STATUS_BASELINE_SEEN));
        $this->assertSame(
            ['https://blog.example.com/p/post-1', 'https://blog.example.com/p/post-5'],
            [$result->entries[0]['url'], $result->entries[4]['url']],
        );

        $event = $result->event;
        $this->assertSame('expanded', $event->action);
        $this->assertSame(1, $event->event_metadata['revision_model_version']);
        $this->assertSame('list', $event->target_metadata['kind']);
        $this->assertSame(5, $event->target_metadata['new_count']);
        $this->assertSame(12, $event->target_metadata['total_count']);
        $this->assertSame((string) $this->list->id, (string) $event->target_id);

        $block = $event->blocks()->where('block_type', 'fetch_link_list')->sole();
        $this->assertSame('baseline', $block->metadata['kind']);
        $this->assertCount(12, $block->metadata['items']);

        Queue::assertPushed(FetchSingleUrl::class, 5);
        $this->assertSame(5, Relationship::where('type', 'linked_to')->where('from_id', $this->list->id)->count());
    }

    #[Test]
    public function children_are_one_time_discovered_bookmarks_that_never_expand(): void
    {
        $this->expand($this->items([1], title: 'A great post'));

        $child = EventObject::where('url', 'https://blog.example.com/p/post-1')->sole();
        $metadata = $child->metadata;

        $this->assertSame('https://blog.example.com/p/post-1', $child->title);
        $this->assertSame('A great post', $metadata['list_item_title']);
        $this->assertSame('once', $metadata['fetch_mode']);
        $this->assertTrue($metadata['enabled']);
        $this->assertSame('discovered', $metadata['subscription_source']);
        $this->assertSame('list_expansion', $metadata['found_in']);
        $this->assertSame(1, $metadata['list_expansion_depth']);
        $this->assertFalse($metadata['is_linkable']);
        $this->assertSame(0, $metadata['fetch_count']);
        $this->assertNull($metadata['last_checked_at']);
        $this->assertSame('pending', $metadata['discovery_status']);
        $this->assertSame((string) $this->list->id, (string) $metadata['discovered_from_object_id']);
        $this->assertNotNull($metadata['fetch_dispatched_at']);
        $this->assertSame('https://blog.example.com/p/post-1', $metadata['canonical_url']);
    }

    #[Test]
    public function later_scans_fetch_only_new_items(): void
    {
        $this->expand($this->items(range(1, 8)));
        Queue::fake();

        $result = $this->expand($this->items(array_merge([101, 102], range(1, 8))));

        $this->assertFalse($result->coldStart);
        $this->assertSame(
            ['https://blog.example.com/p/post-101', 'https://blog.example.com/p/post-102'],
            array_column($result->entries, 'url'),
        );
        $this->assertSame('delta', $result->event->blocks()->first()->metadata['kind']);
        Queue::assertPushed(FetchSingleUrl::class, 2);
    }

    #[Test]
    public function items_over_the_cap_stay_eligible_for_the_next_run(): void
    {
        config(['fetch.list_expansion.max_new_per_run' => 20]);
        $this->expand($this->items([1]));

        $new = $this->items(range(200, 224));
        $first = $this->expand($new);
        $second = $this->expand($new);
        $third = $this->expand($new);

        $this->assertSame(20, $first->newCount());
        $this->assertSame(5, $second->newCount());
        $this->assertNull($third->event);
        $this->assertSame([], $third->entries);
    }

    #[Test]
    public function existing_bookmarks_are_linked_but_not_refetched(): void
    {
        $existing = app(BookmarkCreator::class)->firstOrCreate($this->user->id, 'https://blog.example.com/p/post-1')['bookmark'];

        $result = $this->expand([new ListItem('https://blog.example.com/p/post-1?utm_source=home', 'Post 1')]);

        $this->assertSame(LinkListExpander::STATUS_EXISTING, $result->entries[0]['status']);
        $this->assertSame((string) $existing->id, $result->entries[0]['child_id']);
        $this->assertSame(1, EventObject::where('type', 'fetch_webpage')->where('id', '!=', $this->list->id)->count());
        $this->assertTrue(Relationship::where('from_id', $this->list->id)->where('to_id', $existing->id)->exists());
        Queue::assertNotPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function excluded_domains_and_the_list_itself_are_rejected(): void
    {
        $this->user->setFetchDiscoveryExcludedDomains(['sponsor.example.net']);

        $result = $this->expand([
            new ListItem('https://www.sponsor.example.net/deal', 'Deal'),
            new ListItem('https://blog.example.com/', 'Home'),
        ]);

        $this->assertSame([LinkListExpander::STATUS_REJECTED, LinkListExpander::STATUS_REJECTED], array_column($result->entries, 'status'));
        Queue::assertNotPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function items_are_bookmarked_disabled_when_auto_fetch_is_off(): void
    {
        $this->user->setFetchListExpansionAutoFetchEnabled(false);

        $result = $this->expand($this->items([1, 2]));

        $this->assertSame([LinkListExpander::STATUS_DISABLED, LinkListExpander::STATUS_DISABLED], array_column($result->entries, 'status'));
        $this->assertFalse(EventObject::where('url', 'https://blog.example.com/p/post-1')->sole()->metadata['enabled']);
        Queue::assertNotPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function fetches_to_the_same_host_are_staggered(): void
    {
        config(['fetch.list_expansion.stagger_seconds' => 20]);

        $this->expand([
            new ListItem('https://blog.example.com/p/a', 'A'),
            new ListItem('https://other.example.org/b', 'B'),
            new ListItem('https://blog.example.com/p/c', 'C'),
        ]);

        $delays = [];
        Queue::assertPushed(FetchSingleUrl::class, function (FetchSingleUrl $job) use (&$delays): bool {
            $delays[$job->url] = (int) round(now()->diffInSeconds($job->delay, true));

            return true;
        });

        $this->assertSame(0, $delays['https://blog.example.com/p/a']);
        $this->assertSame(0, $delays['https://other.example.org/b']);
        $this->assertSame(20, $delays['https://blog.example.com/p/c']);
    }

    #[Test]
    public function retryable_failures_are_not_remembered_as_seen(): void
    {
        $this->expand($this->items([1]));
        $event = Event::where('action', 'expanded')->sole();
        Block::create([
            'event_id' => $event->id,
            'block_type' => 'fetch_link_list',
            'title' => 'Articles Found (retry)',
            'time' => now()->addMinute(),
            'metadata' => ['kind' => 'delta', 'items' => [
                ['url' => 'https://blog.example.com/p/post-9', 'canonical_url' => 'https://blog.example.com/p/post-9', 'title' => null, 'status' => 'retryable_failed', 'child_id' => null],
            ]],
        ]);
        app(LinkSeenStateProjector::class)->forget($this->list);

        $projection = app(LinkSeenStateProjector::class)->forBookmark($this->list);

        $this->assertTrue($projection['has_baseline']);
        $this->assertArrayHasKey('https://blog.example.com/p/post-1', $projection['seen']);
        $this->assertArrayNotHasKey('https://blog.example.com/p/post-9', $projection['seen']);
    }

    #[Test]
    public function newsletter_issues_link_from_the_issue_event_without_a_cold_start(): void
    {
        $issue = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'newsletter',
            'action' => 'received_post',
        ]);

        $result = app(LinkListExpander::class)->expandIssue($this->integration, $issue, $this->items(range(1, 7)), ['status' => 'assessed'], 'newsletter_link_list');

        $this->assertFalse($result->coldStart);
        $this->assertSame(7, $result->countWithStatus(LinkListExpander::STATUS_QUEUED));
        $this->assertSame(7, Relationship::where('from_type', Event::class)->where('from_id', $issue->id)->count());
        $this->assertSame(7, $issue->blocks()->where('block_type', 'newsletter_link_list')->sole()->metadata['new_count']);
        $this->assertSame((string) $issue->id, (string) EventObject::where('url', 'https://blog.example.com/p/post-1')->sole()->metadata['discovered_from_event_id']);
    }

    #[Test]
    public function the_job_records_the_expansion_on_the_list_bookmark(): void
    {
        FetchMetadata::merge($this->list, ['list_detection' => ['kind' => 'list', 'shadow' => true, 'expansion_status' => 'pending']]);
        $job = new ExpandLinkListJob($this->integration, (string) $this->list->id, [['url' => 'https://blog.example.com/p/post-1', 'title' => 'Post 1']], ['status' => 'memo']);

        $job->handle(app(LinkListExpander::class));

        $memo = $this->list->fresh()->metadata['list_detection'];
        $this->assertSame('complete', $memo['expansion_status']);
        $this->assertFalse($memo['shadow']);
        $this->assertSame(1, $memo['last_new_count']);
        $this->assertSame((string) Event::where('action', 'expanded')->sole()->id, $memo['latest_expansion_event_id']);
        $this->assertSame('list', $memo['kind']);

        $job->failed(new RuntimeException('boom'));
        $this->assertSame('failed', $this->list->fresh()->metadata['list_detection']['expansion_status']);
        $this->assertStringStartsWith((string) $this->list->id . ':', $job->uniqueId());
    }

    #[Test]
    public function a_one_time_expansion_retries_transient_creation_failures(): void
    {
        $this->partialMock(BookmarkCreator::class, fn ($mock) => $mock
            ->shouldReceive('firstOrCreate')->once()->andThrow(new RuntimeException('Temporary database failure')));
        $job = new ExpandLinkListJob($this->integration, (string) $this->list->id,
            [['url' => 'https://blog.example.com/p/post-9', 'title' => 'Post 9']], []);

        try {
            $job->handle(app(LinkListExpander::class));
            $this->fail('The job must fail so the queue retries it.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retrying unresolved', $exception->getMessage());
        }

        $this->assertSame('retryable_failed', Event::where('action', 'expanded')->sole()->blocks()->sole()->metadata['items'][0]['status']);
        $this->app->instance(BookmarkCreator::class, new BookmarkCreator);
        $job->handle(app(LinkListExpander::class));

        $this->assertSame('complete', $this->list->fresh()->metadata['list_detection']['expansion_status']);
        Queue::assertPushed(FetchSingleUrl::class, 1);
    }

    #[Test]
    public function an_interrupted_dispatch_is_recovered_without_another_bookmark(): void
    {
        $child = app(BookmarkCreator::class)->firstOrCreate($this->user->id,
            'https://blog.example.com/p/post-9', [], [
                'via' => 'list_expansion', 'discovered_from_object_id' => $this->list->id,
                'enabled' => true, 'fetch_count' => 0, 'fetch_dispatched_at' => null,
            ])['bookmark'];

        $result = $this->expand($this->items([9]));

        $this->assertSame('queued', $result->entries[0]['status']);
        $this->assertNotNull($child->fresh()->metadata['fetch_dispatched_at']);
        $this->assertSame(2, EventObject::where('type', 'fetch_webpage')->count());
        Queue::assertPushed(FetchSingleUrl::class, 1);
    }

    #[Test]
    public function a_digest_expands_all_assessed_items_beyond_the_web_run_cap(): void
    {
        config(['fetch.list_expansion.max_new_per_run' => 2]);
        $issue = Event::factory()->create(['integration_id' => $this->integration->id,
            'service' => 'newsletter', 'action' => 'received_post']);

        $result = app(LinkListExpander::class)->expandIssue($this->integration, $issue,
            $this->items(range(1, 25)), [], 'newsletter_link_list');

        $this->assertSame(25, $result->newCount());
        Queue::assertPushed(FetchSingleUrl::class, 25);
    }

    #[Test]
    public function queued_expansion_obeys_current_flags_and_bookmark_mode(): void
    {
        $job = new ExpandLinkListJob($this->integration, (string) $this->list->id,
            [['url' => 'https://blog.example.com/p/post-9', 'title' => 'Post 9']], []);
        foreach ([['enabled' => false, 'shadow' => false], ['enabled' => true, 'shadow' => true]] as $flags) {
            config(['fetch.list_detection.enabled' => $flags['enabled'], 'fetch.list_detection.shadow' => $flags['shadow']]);
            $job->handle(app(LinkListExpander::class));
        }
        config(['fetch.list_detection.enabled' => true, 'fetch.list_detection.shadow' => false]);
        FetchMetadata::merge($this->list, ['list_detection' => ['mode' => 'off']]);
        $job->handle(app(LinkListExpander::class));

        $this->assertSame(1, EventObject::where('type', 'fetch_webpage')->count());
        Queue::assertNotPushed(FetchSingleUrl::class);
    }

    /**
     * @param  list<int>  $numbers
     * @return list<ListItem>
     */
    private function items(array $numbers, ?string $title = null): array
    {
        return array_map(fn (int $n): ListItem => new ListItem("https://blog.example.com/p/post-{$n}", $title ?? "Post {$n}"), $numbers);
    }

    /**
     * @param  list<ListItem>  $items
     */
    private function expand(array $items): ExpansionResult
    {
        return app(LinkListExpander::class)->expandBookmark($this->integration, $this->list, $items, ['status' => 'assessed']);
    }
}
