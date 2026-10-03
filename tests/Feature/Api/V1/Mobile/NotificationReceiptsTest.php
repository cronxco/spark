<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationReceiptsTest extends TestCase
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
    public function each_event_is_stored_once_and_the_first_time_wins(): void
    {
        $notification = $this->notification();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        foreach (['shown', 'opened', 'tapped'] as $event) {
            $this->postJson("/api/v1/mobile/notifications/{$notification->id}/receipts", [
                'event' => $event,
                'occurred_at' => '2026-10-03T09:00:00Z',
            ])->assertNoContent();
        }

        $this->postJson("/api/v1/mobile/notifications/{$notification->id}/receipts", [
            'event' => 'shown',
            'occurred_at' => '2026-10-03T10:00:00Z',
        ])->assertNoContent();

        $receipts = $notification->fresh()->data['receipts'];
        $this->assertSame(['shown', 'opened', 'tapped'], array_keys($receipts));
        $this->assertSame('2026-10-03T09:00:00.000000Z', $receipts['shown']['at']);
        $this->assertArrayHasKey('recorded_at', $receipts['shown']);
        $this->assertArrayNotHasKey('action', $receipts['shown']);
        $this->assertSame('Original title', $notification->fresh()->data['title']);
    }

    #[Test]
    public function a_batch_records_receipts_with_their_action_and_counts_repeats(): void
    {
        $notification = $this->notification();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/notifications/receipts', ['receipts' => [
            ['notification_id' => $notification->id, 'event' => 'opened', 'occurred_at' => '2026-10-03T09:00:00Z', 'action' => 'VIEW'],
            ['notification_id' => $notification->id, 'event' => 'opened', 'occurred_at' => '2026-10-03T09:05:00Z', 'action' => 'REAUTH'],
            ['notification_id' => (string) Str::uuid(), 'event' => 'shown', 'occurred_at' => '2026-10-03T09:00:00Z'],
        ]])
            ->assertOk()
            ->assertExactJson(['data' => ['recorded' => 1, 'unchanged' => 1, 'not_found' => 1]]);

        $opened = $notification->fresh()->data['receipts']['opened'];
        $this->assertSame('VIEW', $opened['action']);
        $this->assertSame('2026-10-03T09:00:00.000000Z', $opened['at']);
    }

    #[Test]
    public function another_users_notification_is_not_found_and_never_written(): void
    {
        $theirs = $this->notification(User::factory()->create());
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/notifications/{$theirs->id}/receipts", [
            'event' => 'shown',
            'occurred_at' => '2026-10-03T09:00:00Z',
        ])->assertNotFound();

        $this->postJson('/api/v1/mobile/notifications/receipts', ['receipts' => [
            ['notification_id' => $theirs->id, 'event' => 'shown', 'occurred_at' => '2026-10-03T09:00:00Z'],
        ]])->assertOk()->assertJsonPath('data.not_found', 1);

        $this->postJson('/api/v1/mobile/notifications/not-a-uuid/receipts', [
            'event' => 'shown',
            'occurred_at' => '2026-10-03T09:00:00Z',
        ])->assertNotFound();

        $this->assertArrayNotHasKey('receipts', $theirs->fresh()->data);
    }

    #[Test]
    public function an_unknown_event_is_rejected(): void
    {
        $notification = $this->notification();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/notifications/{$notification->id}/receipts", [
            'event' => 'dismissed',
            'occurred_at' => '2026-10-03T09:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors('event');

        $this->postJson('/api/v1/mobile/notifications/receipts', ['receipts' => [
            ['notification_id' => $notification->id, 'event' => 'read', 'occurred_at' => '2026-10-03T09:00:00Z'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('receipts.0.event');

        $this->assertArrayNotHasKey('receipts', $notification->fresh()->data);
    }

    #[Test]
    public function content_like_fields_are_rejected_and_not_stored(): void
    {
        $notification = $this->notification();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/notifications/{$notification->id}/receipts", [
            'event' => 'shown',
            'occurred_at' => '2026-10-03T09:00:00Z',
            'title' => 'Leaked title',
            'body' => 'Leaked body',
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'body']);

        $this->postJson('/api/v1/mobile/notifications/receipts', ['receipts' => [
            ['notification_id' => $notification->id, 'event' => 'shown', 'occurred_at' => '2026-10-03T09:00:00Z', 'message' => 'Leaked'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('receipts.0');

        $this->postJson('/api/v1/mobile/notifications/receipts', [
            'receipts' => [['notification_id' => $notification->id, 'event' => 'shown', 'occurred_at' => '2026-10-03T09:00:00Z']],
            'content' => 'Leaked',
        ])->assertUnprocessable()->assertJsonValidationErrors('content');

        $this->postJson("/api/v1/mobile/notifications/{$notification->id}/receipts", [
            'event' => 'tapped',
            'occurred_at' => '2026-10-03T09:00:00Z',
            'action' => 'Reply with: hello there',
        ])->assertUnprocessable()->assertJsonValidationErrors('action');

        $data = $notification->fresh()->data;
        $this->assertArrayNotHasKey('receipts', $data);
        $this->assertStringNotContainsString('Leaked', json_encode($data));
    }

    #[Test]
    public function reporting_needs_the_write_ability(): void
    {
        $notification = $this->notification();
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson("/api/v1/mobile/notifications/{$notification->id}/receipts", [
            'event' => 'shown',
            'occurred_at' => '2026-10-03T09:00:00Z',
        ])->assertForbidden();
    }

    private function notification(?User $user = null): DatabaseNotification
    {
        $user ??= $this->user;

        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Original title', 'body' => 'Original body'],
        ]);
    }
}
