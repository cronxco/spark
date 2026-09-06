<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use App\Notifications\SystemMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * PSEC-07 / NOTIF-01 and NOTIF-02.
 *
 * Every shipped inbox control returned 428, because the routes required an
 * If-Match the client had no way to obtain: the list payload exposed no
 * per-notification version and there was no per-notification GET. The idempotent
 * transitions now carry no precondition, deletion keeps one and the resource
 * emits the version it needs.
 */
class NotificationContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function marking_one_notification_read_no_longer_requires_if_match(): void
    {
        $id = $this->notifyUser();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/notifications/{$id}/read")
            ->assertSuccessful();

        $this->assertNotNull($this->user->notifications()->find($id)->read_at);
    }

    #[Test]
    public function marking_all_read_no_longer_requires_if_match(): void
    {
        $this->notifyUser();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/notifications/read-all')
            ->assertSuccessful();

        $this->assertSame(0, $this->user->unreadNotifications()->count());
    }

    #[Test]
    public function the_list_payload_exposes_the_version_delete_requires(): void
    {
        $this->notifyUser();
        Sanctum::actingAs($this->user, ['ios:read']);

        $response = $this->getJson('/api/v1/mobile/notifications')->assertOk();

        $version = $response->json('data.0.version');

        $this->assertNotEmpty($version, 'Without a version the if-match:notification guard is unsatisfiable.');
        $this->assertStringStartsWith('"', $version);
    }

    #[Test]
    public function delete_succeeds_with_the_version_from_the_list(): void
    {
        $id = $this->notifyUser();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $version = $this->getJson('/api/v1/mobile/notifications')->json('data.0.version');

        $this->withHeader('If-Match', $version)
            ->deleteJson("/api/v1/mobile/notifications/{$id}")
            ->assertSuccessful();

        $this->assertNull($this->user->notifications()->find($id));
    }

    #[Test]
    public function delete_still_refuses_a_missing_precondition(): void
    {
        $id = $this->notifyUser();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        // Deletion is destructive, so it keeps its precondition.
        $this->deleteJson("/api/v1/mobile/notifications/{$id}")->assertStatus(428);
    }

    #[Test]
    public function the_profile_endpoint_emits_the_strong_user_version(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $etag = $this->getJson('/api/v1/mobile/me')->assertOk()->headers->get('ETag');

        $this->assertNotEmpty($etag);
        $this->assertStringStartsNotWith(
            'W/',
            $etag,
            'if-match:user compares against a strong version; a weak ETag can never match.',
        );
    }

    #[Test]
    public function updating_notification_preferences_succeeds_with_the_user_version(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $etag = $this->getJson('/api/v1/mobile/settings/notifications')->assertOk()->headers->get('ETag');

        $this->withHeader('If-Match', $etag)
            ->patchJson('/api/v1/mobile/settings/notifications', [
                'delivery_mode' => 'work_hours',
            ])
            ->assertSuccessful();
    }

    #[Test]
    public function updating_notification_preferences_without_a_precondition_is_still_refused(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        // Preferences are a genuine last-write-wins surface, so this one keeps
        // its precondition — the fix was making the ETag obtainable, not
        // dropping the guard.
        $this->patchJson('/api/v1/mobile/settings/notifications', [
            'delivery_mode' => 'work_hours',
        ])->assertStatus(428);
    }

    /*
     |--------------------------------------------------------------------------
     | APNs vocabulary (NOTIF-02)
     |--------------------------------------------------------------------------
     |
     | The channel sent the raw snake_case notification type as the category
     | identifier. The client registers SCREAMING_CASE identifiers and matching
     | is case-sensitive, so no category ever bound and every action button was
     | inert.
     */

    #[Test]
    public function a_failure_notification_uses_a_category_the_client_registers(): void
    {
        $this->assertSame(
            'INTEGRATION_FAILED',
            $this->categoryFor('integration_failed'),
        );
    }

    #[Test]
    public function every_mapped_category_is_one_the_client_registered(): void
    {
        $registeredByClient = ['ANOMALY', 'DIGEST', 'INTEGRATION_FAILED', 'NEW_BOOKMARK', 'CALENDAR_EVENT'];

        $mapped = $this->clientCategories();

        $this->assertNotEmpty($mapped);
        $this->assertSame(
            [],
            array_diff(array_values($mapped), $registeredByClient),
            'A category identifier the client has not registered shows no action buttons.',
        );
    }

    #[Test]
    public function an_unmapped_type_sends_no_category_rather_than_an_unknown_one(): void
    {
        $this->assertNull($this->categoryFor('system_maintenance'));
    }

    private function notifyUser(): string
    {
        $this->user->notify(new SystemMaintenance('Scheduled maintenance', 'Back shortly.'));

        return (string) $this->user->notifications()->latest()->firstOrFail()->id;
    }

    /** @return array<string, string> */
    private function clientCategories(): array
    {
        $reflection = new ReflectionClass(ApnsChannel::class);

        return $reflection->getConstant('CLIENT_CATEGORIES');
    }

    private function categoryFor(string $notificationType): ?string
    {
        return $this->clientCategories()[$notificationType] ?? null;
    }
}
