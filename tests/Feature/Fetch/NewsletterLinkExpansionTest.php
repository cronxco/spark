<?php

namespace Tests\Feature\Fetch;

use App\Integrations\Newsletter\NewsletterPlugin;
use App\Jobs\Data\Newsletter\ProcessNewsletterEmailJob;
use App\Jobs\Fetch\DiscoverUrlsFromIntegrations;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Jobs\TaskPipeline\Tasks\NewsletterExpandLinksTask;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Fetch\Assessment\NewsletterLinkAssessor;
use App\Services\Fetch\Links\TrackingLinkResolver;
use App\Services\Fetch\UrlSafetyValidator;
use App\Services\Jev\Exceptions\JevUnavailableException;
use App\Services\TaskPipeline\TaskDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\FakeJev;
use Tests\Fixtures\FetchPages;
use Tests\TestCase;

class NewsletterLinkExpansionTest extends TestCase
{
    use RefreshDatabase;

    private const UNSUBSCRIBE = 'https://news.example.com/unsubscribe?u=abc';

    private User $user;

    private Integration $newsletter;

    private ?Integration $fetch = null;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sleep::fake();
        $this->mock(UrlSafetyValidator::class, function ($mock): void {
            $mock->shouldReceive('isSafe')->andReturnTrue();
            $mock->shouldReceive('guzzleRedirectConfig')->andReturn(['max' => 10, 'track_redirects' => true]);
        });
        config(['fetch.list_detection.enabled' => true, 'fetch.list_detection.shadow' => false]);

        $this->user = User::factory()->create();
        $this->newsletter = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'newsletter', 'configuration' => []]);
        $group = IntegrationGroup::create(['user_id' => $this->user->id, 'service' => 'fetch', 'auth_metadata' => ['domains' => []]]);
        $this->fetch = Integration::create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
            'instance_type' => 'fetcher',
            'name' => 'Fetch',
            'integration_group_id' => $group->id,
            'configuration' => [],
        ]);
    }

    #[Test]
    public function the_assessor_accepts_story_links_and_rejects_the_sponsor(): void
    {
        $this->jevSaysDigest();

        $assessment = app(NewsletterLinkAssessor::class)->assess(FetchPages::digestNewsletter(), 'Daily digest', 'Example News', [self::UNSUBSCRIBE]);

        $this->assertTrue($assessment->isList);
        $this->assertCount(6, $assessment->acceptedItems);
        $this->assertCount(1, $assessment->rejectedItemIds);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === FakeJev::URL
                && $request['state']['newsletter']['subject'] === 'Daily digest'
                && count($request['state']['links']) === 7
                && ! str_contains(json_encode($request['state']), 'nsubscribe');
        });
    }

    #[Test]
    public function the_assessor_rejects_issues_that_are_not_digests(): void
    {
        FakeJev::fake(['newsletter_kind' => 'single_essay', 'link_*_is_article' => 0.9]);

        $this->assertFalse(app(NewsletterLinkAssessor::class)->assess(FetchPages::digestNewsletter(), 'Essay', 'Writer')->isList);
    }

    #[Test]
    public function the_assessor_lets_unavailability_fail_the_task_for_a_retry(): void
    {
        FakeJev::unavailable();

        $this->expectException(JevUnavailableException::class);

        app(NewsletterLinkAssessor::class)->assess(FetchPages::digestNewsletter(), 'Digest', 'News');
    }

    #[Test]
    public function a_digest_issue_bookmarks_its_articles_without_touching_unsubscribe_links(): void
    {
        $this->jevSaysDigest();
        Http::fake(['https://tracking.example-mail.com/*' => Http::response('', 200)]);
        $issue = $this->issue();

        $this->runTask($issue);

        $issue->refresh();
        $this->assertSame('link_digest', $issue->event_metadata['link_assessment']['kind']);
        $this->assertSame(6, $issue->blocks()->where('block_type', 'newsletter_link_list')->sole()->metadata['new_count']);
        $this->assertSame(6, EventObject::where('type', 'fetch_webpage')->count());
        $this->assertSame(6, Relationship::where('from_type', Event::class)->where('from_id', $issue->id)->count());
        Queue::assertPushed(FetchSingleUrl::class, 6);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'unsubscribe') || str_contains($request->url(), '/c/7abc'));
    }

    #[Test]
    public function shadow_mode_records_the_assessment_only(): void
    {
        config(['fetch.list_detection.shadow' => true]);
        $this->jevSaysDigest();
        $issue = $this->issue();

        $this->runTask($issue);

        $this->assertTrue($issue->fresh()->event_metadata['link_assessment']['shadow']);
        $this->assertSame(0, EventObject::where('type', 'fetch_webpage')->count());
    }

    #[Test]
    public function users_without_a_fetch_integration_are_skipped(): void
    {
        $this->fetch->delete();
        $this->jevSaysDigest();
        $issue = $this->issue();

        $this->runTask($issue);

        $this->assertSame('skipped', $issue->fresh()->event_metadata['link_assessment']['status']);
        $this->assertSame(0, Integration::where('service', 'fetch')->count());
        Http::assertNotSent(fn (Request $request): bool => $request->url() === FakeJev::URL);
    }

    #[Test]
    public function the_task_only_runs_when_enabled_and_not_yet_assessed(): void
    {
        $definition = collect(NewsletterPlugin::getTaskDefinitions())->firstWhere('key', 'newsletter_expand_links');
        $issue = $this->issue();

        $this->assertTrue($definition->isApplicableTo($issue));

        $this->newsletter->update(['configuration' => ['expand_links' => false]]);
        $this->assertFalse($definition->isApplicableTo($issue->fresh()));

        $this->newsletter->update(['configuration' => []]);
        config(['fetch.list_detection.enabled' => false]);
        $this->assertFalse($definition->isApplicableTo($issue->fresh()));

        config(['fetch.list_detection.enabled' => true]);
        $issue->update(['event_metadata' => $issue->event_metadata + ['link_assessment' => ['status' => 'assessed']]]);
        $this->assertFalse($definition->isApplicableTo($issue->fresh()));
    }

    #[Test]
    public function the_resolver_follows_tracking_redirects(): void
    {
        Http::fake([
            'https://tracking.example-mail.com/*' => Http::response('', 302, ['Location' => 'https://hop.example.net/r']),
            'https://hop.example.net/r' => Http::response('', 302, ['Location' => 'https://news.example.org/story-1']),
            'https://news.example.org/story-1' => Http::response('', 200),
        ]);

        $this->assertSame('https://news.example.org/story-1', app(TrackingLinkResolver::class)->resolve('https://tracking.example-mail.com/c/1abc'));
        Http::assertSentCount(3);
    }

    #[Test]
    public function redirect_resolution_never_requests_unsubscribe_or_private_destinations(): void
    {
        $start = 'https://tracking.example-mail.com/c/abc';
        $destination = 'https://news.example.org/x/opaque';
        Http::fake([$start => Http::response('', 302, ['Location' => $destination])]);

        $this->assertNull(app(TrackingLinkResolver::class)->resolve($start, [$destination]));
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $destination);
    }

    #[Test]
    public function redirect_resolution_checks_encoded_housekeeping_before_get_fallback(): void
    {
        $start = 'https://tracking.example-mail.com/c/abc';
        $destination = 'https://news.example.org/%75nsubscribe?key=abc';
        Http::fake([$start => Http::sequence()->push('', 405)->push('', 302, ['Location' => $destination])]);

        $this->assertNull(app(TrackingLinkResolver::class)->resolve($start));
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $destination);
    }

    #[Test]
    public function the_resolver_keeps_the_original_url_when_it_cannot_resolve(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertSame('https://tracking.example-mail.com/c/2abc', app(TrackingLinkResolver::class)->resolve('https://tracking.example-mail.com/c/2abc'));
    }

    #[Test]
    public function discovery_skips_unsubscribe_urls_and_expanded_newsletter_html(): void
    {
        $this->fetch->update(['configuration' => ['monitor_integrations' => [$this->newsletter->id]]]);
        $this->issue(extraMetadata: ['summary_url' => 'https://example.org/kept']);

        (new DiscoverUrlsFromIntegrations($this->fetch->fresh()))->handle();

        $urls = EventObject::where('type', 'fetch_webpage')->pluck('url');
        $this->assertTrue($urls->contains('https://example.org/kept'));
        $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, 'example-mail.com') || str_contains($url, 'news.example.com')));
    }

    #[Test]
    public function discovery_still_scans_newsletter_html_when_expansion_is_off(): void
    {
        $this->newsletter->update(['configuration' => ['expand_links' => false]]);
        $this->fetch->update(['configuration' => ['monitor_integrations' => [$this->newsletter->id]]]);
        $this->issue();

        (new DiscoverUrlsFromIntegrations($this->fetch->fresh()))->handle();

        $urls = EventObject::where('type', 'fetch_webpage')->pluck('url');
        $this->assertTrue($urls->contains('https://tracking.example-mail.com/c/1abc'));
        $this->assertFalse($urls->contains(self::UNSUBSCRIBE), 'List-Unsubscribe URLs are never discovered');
    }

    #[Test]
    public function legacy_discovery_never_fetches_opaque_unsubscribe_links_or_their_copies(): void
    {
        $this->newsletter->update(['configuration' => ['expand_links' => false]]);
        $this->fetch->update(['configuration' => ['monitor_integrations' => [$this->newsletter->id]]]);
        $opaque = 'https://tracking.example-mail.com/c/opaque';
        $this->issue(['raw_html' => '<a href="' . $opaque . '">Unsubscribe</a>'
            . '<a href="https://news.example.org/story">A story worth reading</a>',
            'raw_text' => 'Unsubscribe ' . $opaque, 'list_unsubscribe' => [$opaque],
            'nested' => ['url' => $opaque]]);

        (new DiscoverUrlsFromIntegrations($this->fetch->fresh()))->handle();

        $urls = EventObject::where('type', 'fetch_webpage')->pluck('url');
        $this->assertFalse($urls->contains($opaque));
        $this->assertTrue($urls->contains('https://news.example.org/story'));
    }

    #[Test]
    public function a_queued_task_obeys_changed_newsletter_and_global_settings(): void
    {
        Http::fake();
        $issue = $this->issue();
        $this->newsletter->update(['configuration' => ['expand_links' => false]]);
        $this->runTask($issue);
        $this->newsletter->update(['configuration' => []]);
        config(['fetch.list_detection.enabled' => false]);
        $this->runTask($issue);

        Http::assertNothingSent();
        Queue::assertNotPushed(FetchSingleUrl::class);
        $this->assertSame(0, EventObject::where('type', 'fetch_webpage')->count());
    }

    #[Test]
    public function incoming_emails_record_their_list_unsubscribe_urls(): void
    {
        $email = "From: Example News <news@example.com>\r\n"
            . "To: news@spark.cronx.co\r\n"
            . "Subject: Daily digest\r\n"
            . 'Message-ID: <' . uniqid() . "@example.com>\r\n"
            . "List-Unsubscribe: <mailto:unsub@example.com?subject=unsubscribe>, <https://news.example.com/unsubscribe?u=abc>\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n\r\n"
            . FetchPages::digestNewsletter();

        (new ProcessNewsletterEmailJob($this->newsletter, null, $email))->handle();

        $event = Event::where('integration_id', $this->newsletter->id)->sole();
        $this->assertSame([self::UNSUBSCRIBE], $event->event_metadata['list_unsubscribe']);
    }

    private function jevSaysDigest(): void
    {
        FakeJev::fake([
            'newsletter_kind' => 'link_digest',
            'link_l7_role' => 'sponsor_ad',
            'link_*_role' => 'article_or_story',
            'link_*_is_article' => 0.9,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function issue(array $extraMetadata = []): Event
    {
        $publication = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'publication',
            'type' => 'newsletter_publication',
        ]);

        return Event::factory()->create([
            'integration_id' => $this->newsletter->id,
            'target_id' => $publication->id,
            'service' => 'newsletter',
            'domain' => 'knowledge',
            'action' => 'received_post',
            'event_metadata' => array_merge([
                'email_subject' => 'Daily digest',
                'email_from_name' => 'Example News',
                'raw_html' => str_replace('https://news.example.com/unsubscribe?u=abc', 'https://news.example.com/x/abc', FetchPages::digestNewsletter()),
                'list_unsubscribe' => [self::UNSUBSCRIBE],
            ], $extraMetadata),
        ]);
    }

    private function runTask(Event $issue): void
    {
        (new NewsletterExpandLinksTask($issue, new TaskDefinition(
            key: 'newsletter_expand_links',
            name: 'Newsletter: Bookmark Digest Articles',
            description: 'Bookmark the articles a digest links to',
            jobClass: NewsletterExpandLinksTask::class,
            appliesTo: ['event'],
        )))->handle();
    }
}
