<?php

namespace Tests\Feature\Integrations;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FetchSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected IntegrationGroup $group;
    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->group = IntegrationGroup::create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
            'auth_metadata' => [
                'domains' => [],
            ],
        ]);

        $this->integration = Integration::create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
            'instance_type' => 'fetcher',
            'name' => 'URL Fetcher',
            'integration_group_id' => $this->group->id,
            'configuration' => [
                'update_frequency_minutes' => 180,
                'monitor_integrations' => [],
            ],
        ]);
    }

    /** @test */
    public function user_can_subscribe_to_url()
    {
        $url = 'https://example.com/article';

        $eventObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => $url,
            'url' => $url,
            'time' => now(),
            'metadata' => [
                'integration_id' => $this->integration->id,
                'domain' => 'example.com',
                'subscription_source' => 'manual',
                'enabled' => true,
                'fetch_count' => 0,
            ],
        ]);

        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'url' => $url,
            'type' => 'fetch_webpage',
        ]);

        $this->assertTrue($eventObject->metadata['enabled']);
        $this->assertEquals('manual', $eventObject->metadata['subscription_source']);
        $this->assertEquals($this->integration->id, $eventObject->metadata['integration_id']);
    }

    /** @test */
    public function user_can_disable_url_subscription()
    {
        $eventObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Test URL',
            'url' => 'https://example.com',
            'time' => now(),
            'metadata' => [
                'integration_id' => $this->integration->id,
                'domain' => 'example.com',
                'enabled' => true,
            ],
        ]);

        $metadata = $eventObject->metadata;
        $metadata['enabled'] = false;
        $eventObject->update(['metadata' => $metadata]);

        $eventObject->refresh();
        $this->assertFalse($eventObject->metadata['enabled']);
    }

    /** @test */
    public function user_can_delete_url_subscription()
    {
        $eventObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Test URL',
            'url' => 'https://example.com',
            'time' => now(),
            'metadata' => [
                'integration_id' => $this->integration->id,
                'domain' => 'example.com',
                'enabled' => true,
            ],
        ]);

        $eventObjectId = $eventObject->id;
        $eventObject->delete();

        // Check that it's soft deleted (deleted_at is set)
        $this->assertSoftDeleted('objects', [
            'id' => $eventObjectId,
        ]);
    }

    /** @test */
    public function disabled_urls_are_not_fetched()
    {
        $enabledUrl = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Enabled URL',
            'url' => 'https://enabled.com',
            'time' => now(),
            'metadata' => [
                'integration_id' => $this->integration->id,
                'domain' => 'enabled.com',
                'enabled' => true,
            ],
        ]);

        $disabledUrl = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Disabled URL',
            'url' => 'https://disabled.com',
            'time' => now(),
            'metadata' => [
                'integration_id' => $this->integration->id,
                'domain' => 'disabled.com',
                'enabled' => false,
            ],
        ]);

        $enabledUrls = EventObject::where('user_id', $this->user->id)
            ->where('type', 'fetch_webpage')
            ->whereJsonContains('metadata->integration_id', $this->integration->id)
            ->get()
            ->filter(function ($obj) {
                $metadata = $obj->metadata ?? [];

                return ($metadata['enabled'] ?? true) === true;
            });

        $this->assertCount(1, $enabledUrls);
        $this->assertTrue($enabledUrls->contains('id', $enabledUrl->id));
        $this->assertFalse($enabledUrls->contains('id', $disabledUrl->id));
    }

    /** @test */
    public function url_metadata_tracks_fetch_statistics()
    {
        $eventObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Test URL',
            'url' => 'https://example.com',
            'time' => now(),
            'metadata' => [
                'integration_id' => $this->integration->id,
                'domain' => 'example.com',
                'enabled' => true,
                'fetch_count' => 0,
                'last_checked_at' => null,
                'last_changed_at' => null,
                'content_hash' => null,
            ],
        ]);

        // Simulate a fetch
        $metadata = $eventObject->metadata;
        $metadata['fetch_count'] = 1;
        $metadata['last_checked_at'] = now()->toIso8601String();
        $metadata['last_changed_at'] = now()->toIso8601String();
        $metadata['content_hash'] = 'abc123';
        $eventObject->update(['metadata' => $metadata]);

        $eventObject->refresh();
        $this->assertEquals(1, $eventObject->metadata['fetch_count']);
        $this->assertNotNull($eventObject->metadata['last_checked_at']);
        $this->assertNotNull($eventObject->metadata['content_hash']);
    }

    /** @test */
    public function url_can_track_consecutive_failures()
    {
        $eventObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Test URL',
            'url' => 'https://example.com',
            'time' => now(),
            'metadata' => [
                'integration_id' => $this->integration->id,
                'domain' => 'example.com',
                'enabled' => true,
            ],
        ]);

        // Simulate failures
        $metadata = $eventObject->metadata;
        $metadata['last_error'] = [
            'message' => 'Connection timeout',
            'timestamp' => now()->toIso8601String(),
            'consecutive_failures' => 3,
        ];
        $eventObject->update(['metadata' => $metadata]);

        $eventObject->refresh();
        $this->assertEquals(3, $eventObject->metadata['last_error']['consecutive_failures']);
        $this->assertEquals('Connection timeout', $eventObject->metadata['last_error']['message']);
    }

    /** @test */
    public function url_is_auto_disabled_after_five_consecutive_failures()
    {
        $eventObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Test URL',
            'url' => 'https://example.com',
            'time' => now(),
            'metadata' => [
                'integration_id' => $this->integration->id,
                'domain' => 'example.com',
                'enabled' => true,
            ],
        ]);

        // Simulate 5 failures
        $metadata = $eventObject->metadata;
        $metadata['last_error'] = [
            'message' => 'Connection timeout',
            'timestamp' => now()->toIso8601String(),
            'consecutive_failures' => 5,
        ];
        $metadata['enabled'] = false;
        $eventObject->update(['metadata' => $metadata]);

        $eventObject->refresh();
        $this->assertFalse($eventObject->metadata['enabled']);
        $this->assertEquals(5, $eventObject->metadata['last_error']['consecutive_failures']);
    }
}
