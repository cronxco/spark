<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Fetch\DiscoverUrlsFromIntegrations;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FetchDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected IntegrationGroup $fetchGroup;
    protected Integration $fetchIntegration;
    protected Integration $sourceIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create Fetch integration
        $this->fetchGroup = IntegrationGroup::create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
            'auth_metadata' => ['domains' => []],
        ]);

        $this->fetchIntegration = Integration::create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
            'instance_type' => 'fetcher',
            'name' => 'URL Fetcher',
            'integration_group_id' => $this->fetchGroup->id,
            'configuration' => [
                'monitor_integrations' => [],
            ],
        ]);

        // Create a source integration (e.g., Karakeep)
        $sourceGroup = IntegrationGroup::create([
            'user_id' => $this->user->id,
            'service' => 'karakeep',
        ]);

        $this->sourceIntegration = Integration::create([
            'user_id' => $this->user->id,
            'service' => 'karakeep',
            'instance_type' => 'bookmarks',
            'name' => 'Karakeep Bookmarks',
            'integration_group_id' => $sourceGroup->id,
            'configuration' => [],
        ]);
    }

    /** @test */
    public function it_discovers_urls_from_event_object_url_field()
    {
        // Configure Fetch to monitor the source integration
        $this->fetchIntegration->update([
            'configuration' => [
                'monitor_integrations' => [$this->sourceIntegration->id],
            ],
        ]);

        // Create an EventObject with a URL
        $object = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'karakeep_bookmark',
            'title' => 'Interesting Article',
            'url' => 'https://example.com/article',
            'time' => now(),
        ]);

        // Create an event to link this object to the source integration
        Event::create([
            'source_id' => 'test_event_1',
            'time' => now(),
            'integration_id' => $this->sourceIntegration->id,
            'actor_id' => $object->id,
            'target_id' => $object->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'bookmarked',
        ]);

        // Run discovery
        DiscoverUrlsFromIntegrations::dispatchSync($this->fetchIntegration);

        // Check that the URL was discovered
        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'type' => 'fetch_webpage',
            'url' => 'https://example.com/article',
        ]);

        $discoveredUrl = EventObject::where('url', 'https://example.com/article')
            ->where('type', 'fetch_webpage')
            ->first();

        $this->assertEquals('discovered', $discoveredUrl->metadata['subscription_source']);
        $this->assertEquals($this->sourceIntegration->id, $discoveredUrl->metadata['discovered_from_integration_id']);
    }

    /** @test */
    public function it_discovers_urls_from_event_object_metadata()
    {
        $this->fetchIntegration->update([
            'configuration' => [
                'monitor_integrations' => [$this->sourceIntegration->id],
            ],
        ]);

        $object = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'note',
            'type' => 'outline_document',
            'title' => 'My Notes',
            'time' => now(),
            'metadata' => [
                'links' => [
                    'https://research.com/paper',
                    'https://blog.com/article',
                ],
            ],
        ]);

        // Create an event to link this object to the source integration
        Event::create([
            'source_id' => 'test_event_2',
            'time' => now(),
            'integration_id' => $this->sourceIntegration->id,
            'actor_id' => $object->id,
            'target_id' => $object->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'created',
        ]);

        DiscoverUrlsFromIntegrations::dispatchSync($this->fetchIntegration);

        $this->assertDatabaseHas('objects', [
            'type' => 'fetch_webpage',
            'url' => 'https://research.com/paper',
        ]);

        $this->assertDatabaseHas('objects', [
            'type' => 'fetch_webpage',
            'url' => 'https://blog.com/article',
        ]);
    }

    /** @test */
    public function it_discovers_urls_from_event_metadata()
    {
        $this->fetchIntegration->update([
            'configuration' => [
                'monitor_integrations' => [$this->sourceIntegration->id],
            ],
        ]);

        // Create actor and target EventObjects (required by schema)
        $actor = EventObject::create([
            'user_id' => $this->user->id,
            'integration_id' => $this->sourceIntegration->id,
            'concept' => 'bookmark',
            'type' => 'karakeep_bookmark',
            'title' => 'Test Actor',
            'time' => now(),
        ]);

        $target = EventObject::create([
            'user_id' => $this->user->id,
            'integration_id' => $this->sourceIntegration->id,
            'concept' => 'bookmark',
            'type' => 'karakeep_bookmark',
            'title' => 'Test Target',
            'time' => now(),
        ]);

        Event::create([
            'integration_id' => $this->sourceIntegration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'saved',
            'source_id' => 'test_event_1',
            'time' => now(),
            'event_metadata' => [
                'url' => 'https://news.com/breaking',
                'source_url' => 'https://original.com/story',
            ],
        ]);

        DiscoverUrlsFromIntegrations::dispatchSync($this->fetchIntegration);

        $this->assertDatabaseHas('objects', [
            'type' => 'fetch_webpage',
            'url' => 'https://news.com/breaking',
        ]);

        $this->assertDatabaseHas('objects', [
            'type' => 'fetch_webpage',
            'url' => 'https://original.com/story',
        ]);
    }

    /** @test */
    public function it_deduplicates_discovered_urls()
    {
        $this->fetchIntegration->update([
            'configuration' => [
                'monitor_integrations' => [$this->sourceIntegration->id],
            ],
        ]);

        // Create multiple EventObjects with the same URL
        $object1 = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'karakeep_bookmark',
            'title' => 'Article 1',
            'url' => 'https://example.com/same-article',
            'time' => now(),
        ]);

        Event::create([
            'source_id' => 'test_event_dedup_1',
            'time' => now(),
            'integration_id' => $this->sourceIntegration->id,
            'actor_id' => $object1->id,
            'target_id' => $object1->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'bookmarked',
        ]);

        $object2 = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'karakeep_bookmark',
            'title' => 'Article 2',
            'url' => 'https://example.com/same-article',
            'time' => now(),
        ]);

        Event::create([
            'source_id' => 'test_event_dedup_2',
            'time' => now(),
            'integration_id' => $this->sourceIntegration->id,
            'actor_id' => $object2->id,
            'target_id' => $object2->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'bookmarked',
        ]);

        DiscoverUrlsFromIntegrations::dispatchSync($this->fetchIntegration);

        // Should only create one fetch_webpage EventObject
        $count = EventObject::where('type', 'fetch_webpage')
            ->where('url', 'https://example.com/same-article')
            ->count();

        $this->assertEquals(1, $count);
    }

    /** @test */
    public function it_does_not_rediscover_existing_urls()
    {
        $this->fetchIntegration->update([
            'configuration' => [
                'monitor_integrations' => [$this->sourceIntegration->id],
            ],
        ]);

        // Manually subscribe to a URL
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Already Subscribed',
            'url' => 'https://already-subscribed.com',
            'time' => now(),
            'metadata' => [
                'fetch_integration_id' => $this->fetchIntegration->id,
                'subscription_source' => 'manual',
            ],
        ]);

        // Create a source EventObject with the same URL
        $object = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'karakeep_bookmark',
            'title' => 'Found in Source',
            'url' => 'https://already-subscribed.com',
            'time' => now(),
        ]);

        Event::create([
            'source_id' => 'test_event_existing_url',
            'time' => now(),
            'integration_id' => $this->sourceIntegration->id,
            'actor_id' => $object->id,
            'target_id' => $object->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'bookmarked',
        ]);

        DiscoverUrlsFromIntegrations::dispatchSync($this->fetchIntegration);

        // Should still only have one fetch_webpage for this URL
        $count = EventObject::where('type', 'fetch_webpage')
            ->where('url', 'https://already-subscribed.com')
            ->count();

        $this->assertEquals(1, $count);

        // Verify it's still the manual one
        $url = EventObject::where('type', 'fetch_webpage')
            ->where('url', 'https://already-subscribed.com')
            ->first();

        $this->assertEquals('manual', $url->metadata['subscription_source']);
    }

    /** @test */
    public function it_only_discovers_from_configured_integrations()
    {
        // Create another integration that is NOT monitored
        $otherGroup = IntegrationGroup::create([
            'user_id' => $this->user->id,
            'service' => 'reddit',
        ]);

        $otherIntegration = Integration::create([
            'user_id' => $this->user->id,
            'service' => 'reddit',
            'instance_type' => 'saved',
            'name' => 'Reddit Saved',
            'integration_group_id' => $otherGroup->id,
            'configuration' => [],
        ]);

        // Configure Fetch to ONLY monitor the source integration
        $this->fetchIntegration->update([
            'configuration' => [
                'monitor_integrations' => [$this->sourceIntegration->id],
            ],
        ]);

        // Create URLs in both integrations
        $object1 = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'karakeep_bookmark',
            'title' => 'Monitored Source',
            'url' => 'https://monitored.com',
            'time' => now(),
        ]);

        Event::create([
            'source_id' => 'test_event_monitored',
            'time' => now(),
            'integration_id' => $this->sourceIntegration->id,
            'actor_id' => $object1->id,
            'target_id' => $object1->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'bookmarked',
        ]);

        $object2 = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'saved',
            'type' => 'reddit_post',
            'title' => 'Not Monitored',
            'url' => 'https://not-monitored.com',
            'time' => now(),
        ]);

        Event::create([
            'source_id' => 'test_event_not_monitored',
            'time' => now(),
            'integration_id' => $otherIntegration->id,
            'actor_id' => $object2->id,
            'target_id' => $object2->id,
            'service' => 'reddit',
            'domain' => 'online',
            'action' => 'saved',
        ]);

        DiscoverUrlsFromIntegrations::dispatchSync($this->fetchIntegration);

        // Should only discover from monitored integration
        $this->assertDatabaseHas('objects', [
            'type' => 'fetch_webpage',
            'url' => 'https://monitored.com',
        ]);

        $this->assertDatabaseMissing('objects', [
            'type' => 'fetch_webpage',
            'url' => 'https://not-monitored.com',
        ]);
    }

    /** @test */
    public function it_handles_empty_monitor_integrations_gracefully()
    {
        // No integrations configured to monitor
        $this->fetchIntegration->update([
            'configuration' => [
                'monitor_integrations' => [],
            ],
        ]);

        // Create some URLs in other integrations
        $object = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'karakeep_bookmark',
            'title' => 'Example Bookmark',
            'url' => 'https://example.com',
            'time' => now(),
        ]);

        Event::create([
            'source_id' => 'test_event_empty_monitor',
            'time' => now(),
            'integration_id' => $this->sourceIntegration->id,
            'actor_id' => $object->id,
            'target_id' => $object->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'bookmarked',
        ]);

        // Should not throw an error
        DiscoverUrlsFromIntegrations::dispatchSync($this->fetchIntegration);

        // Should not discover any URLs
        $count = EventObject::where('type', 'fetch_webpage')
            ->where('user_id', $this->user->id)
            ->count();

        $this->assertEquals(0, $count);
    }
}
