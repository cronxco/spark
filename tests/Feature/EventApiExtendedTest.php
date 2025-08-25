<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventApiExtendedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * @test
     */
    public function user_can_list_events()
    {
        Sanctum::actingAs($this->user);

        // Create test events
        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'test-service',
            'domain' => 'test-domain',
        ]);

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'another-service',
            'domain' => 'another-domain',
        ]);

        $response = $this->getJson('/api/events');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'source_id',
                    'time',
                    'integration_id',
                    'service',
                    'domain',
                    'action',
                    'created_at',
                    'updated_at',
                ],
            ],
            'current_page',
            'per_page',
            'total',
        ]);

        $this->assertCount(2, $response->json('data'));
    }

    /**
     * @test
     */
    public function user_can_filter_events_by_service()
    {
        Sanctum::actingAs($this->user);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'test-service',
        ]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'another-service',
        ]);

        $response = $this->getJson('/api/events?service=test-service');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('test-service', $response->json('data.0.service'));
    }

    /**
     * @test
     */
    public function user_can_filter_events_by_domain()
    {
        Sanctum::actingAs($this->user);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'domain' => 'test-domain',
        ]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'domain' => 'another-domain',
        ]);

        $response = $this->getJson('/api/events?domain=test-domain');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('test-domain', $response->json('data.0.domain'));
    }

    /**
     * @test
     */
    public function user_can_get_specific_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $response = $this->getJson("/api/events/{$event->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $event->id,
            'integration_id' => $this->integration->id,
        ]);
    }

    /**
     * @test
     */
    public function user_cannot_access_other_users_event()
    {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $event = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
        ]);

        $response = $this->getJson("/api/events/{$event->id}");

        $response->assertStatus(404);
    }

    /**
     * @test
     */
    public function user_can_update_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'old-service',
            'domain' => 'old-domain',
        ]);

        $response = $this->putJson("/api/events/{$event->id}", [
            'service' => 'new-service',
            'domain' => 'new-domain',
            'action' => 'updated-action',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'service' => 'new-service',
            'domain' => 'new-domain',
            'action' => 'updated-action',
        ]);

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'service' => 'new-service',
            'domain' => 'new-domain',
        ]);
    }

    /**
     * @test
     */
    public function user_cannot_update_other_users_event()
    {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $event = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
        ]);

        $response = $this->putJson("/api/events/{$event->id}", [
            'service' => 'new-service',
        ]);

        $response->assertStatus(404);
    }

    /**
     * @test
     */
    public function user_can_delete_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        // Create some blocks for this event
        Block::factory()->create([
            'event_id' => $event->id,
        ]);

        $response = $this->deleteJson("/api/events/{$event->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Event deleted successfully']);

        // Since we now use soft deletes, the records should still exist but be soft deleted
        $this->assertDatabaseHas('events', ['id' => $event->id]);
        $this->assertDatabaseHas('blocks', ['event_id' => $event->id]);

        // Check that they are soft deleted
        $deletedEvent = Event::withTrashed()->find($event->id);
        $this->assertNotNull($deletedEvent->deleted_at);

        $deletedBlock = Block::withTrashed()->where('event_id', $event->id)->first();
        $this->assertNotNull($deletedBlock->deleted_at);
    }

    /**
     * @test
     */
    public function user_cannot_delete_other_users_event()
    {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $event = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
        ]);

        $response = $this->deleteJson("/api/events/{$event->id}");

        $response->assertStatus(404);
    }

    /**
     * @test
     */
    public function unauthenticated_user_cannot_access_events()
    {
        $response = $this->getJson('/api/events');
        $response->assertStatus(401);

        $event = Event::factory()->create();

        $response = $this->getJson("/api/events/{$event->id}");
        $response->assertStatus(401);

        $response = $this->putJson("/api/events/{$event->id}", []);
        $response->assertStatus(401);

        $response = $this->deleteJson("/api/events/{$event->id}");
        $response->assertStatus(401);
    }

    /**
     * @test
     */
    public function events_are_paginated()
    {
        Sanctum::actingAs($this->user);

        // Create more than the default per_page (15)
        Event::factory()->count(20)->create([
            'integration_id' => $this->integration->id,
        ]);

        $response = $this->getJson('/api/events');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'current_page',
            'per_page',
            'total',
            'last_page',
        ]);

        $this->assertCount(15, $response->json('data')); // Default per_page
        $this->assertEquals(20, $response->json('total'));
    }

    /**
     * @test
     */
    public function user_can_specify_per_page()
    {
        Sanctum::actingAs($this->user);

        Event::factory()->count(10)->create([
            'integration_id' => $this->integration->id,
        ]);

        $response = $this->getJson('/api/events?per_page=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
        $this->assertEquals(5, $response->json('per_page'));
    }
}
