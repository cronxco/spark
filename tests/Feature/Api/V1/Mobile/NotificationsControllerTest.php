<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/notifications')->assertStatus(401);
    }

    #[Test]
    public function returns_paginated_notifications_for_the_authenticated_user(): void
    {
        $this->notification(['title' => 'First', 'message' => 'Newest'], Carbon::now());
        $this->notification(['title' => 'Second', 'message' => 'Older'], Carbon::now()->subMinute());
        $this->notification(['title' => 'Other user'], Carbon::now(), User::factory()->create());

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/notifications?limit=1')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'body', 'domain', 'is_read', 'received_at', 'entity']],
                'next_cursor',
                'has_more',
            ])
            ->assertJsonPath('data.0.title', 'First')
            ->assertJsonPath('data.0.body', 'Newest')
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('has_more', true);

        $this->assertNotNull($response->json('next_cursor'));
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function paginates_with_cursor(): void
    {
        $this->notification(['title' => 'First'], Carbon::now());
        $this->notification(['title' => 'Second'], Carbon::now()->subMinute());
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $first = $this->getJson('/api/v1/mobile/notifications?limit=1')->assertOk();
        $cursor = $first->json('next_cursor');

        $this->getJson('/api/v1/mobile/notifications?limit=1&cursor=' . urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Second')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);
    }

    #[Test]
    public function maps_domain_and_entity_when_present(): void
    {
        $entityId = (string) Str::uuid();
        $this->notification([
            'title' => 'Integration alert',
            'message' => 'Reconnect needed',
            'domain' => 'money',
            'entity_type' => 'integration',
            'entity_id' => $entityId,
        ], Carbon::now());

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'money')
            ->assertJsonPath('data.0.entity.kind', 'integration')
            ->assertJsonPath('data.0.entity.id', $entityId);
    }

    #[Test]
    public function mark_read_requires_ios_write_ability(): void
    {
        $notification = $this->notification(['title' => 'Unread'], Carbon::now());
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson("/api/v1/mobile/notifications/{$notification->id}/read")
            ->assertStatus(403);
    }

    #[Test]
    public function marks_a_notification_as_read(): void
    {
        $notification = $this->notification(['title' => 'Unread'], Carbon::now());
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/notifications/{$notification->id}/read")
            ->assertNoContent();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    #[Test]
    public function marks_all_notifications_as_read(): void
    {
        $this->notification(['title' => 'One'], Carbon::now());
        $this->notification(['title' => 'Two'], Carbon::now()->subMinute());
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/notifications/read-all')
            ->assertNoContent();

        $this->assertSame(0, $this->user->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function deletes_a_notification(): void
    {
        $notification = $this->notification(['title' => 'Remove me'], Carbon::now());
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->deleteJson("/api/v1/mobile/notifications/{$notification->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    #[Test]
    public function api_route_404s_are_sanitized_json(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/not-a-route')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Not found.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notification(array $data, Carbon $createdAt, ?User $user = null): DatabaseNotification
    {
        $user ??= $this->user;

        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => $data,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
