<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use App\Notifications\NotificationCatalogue;
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
     | The notification taxonomy (NOTIF-02, NOTIF-03)
     |--------------------------------------------------------------------------
     |
     | The channel sent the raw snake_case notification type as the category
     | identifier. The client registers SCREAMING_CASE identifiers and matching
     | is case-sensitive, so no category ever bound and every action button was
     | inert.
     |
     | Separately, the mobile API invented five categories of which only one
     | corresponded to a notification that is ever sent, while three real types
     | had no toggle at all. NotificationCatalogue is now the one source of
     | truth for all four consumers.
     */

    #[Test]
    public function every_catalogue_type_is_a_type_a_notification_actually_declares(): void
    {
        $declared = [];

        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\' . basename($file, '.php');

            if (! class_exists($class) || ! method_exists($class, 'getNotificationType')) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $declared[] = $reflection->newInstanceWithoutConstructor()->getNotificationType();
        }

        $this->assertNotEmpty($declared);
        $this->assertSame(
            [],
            array_diff(array_keys(NotificationCatalogue::all()), $declared),
            'The catalogue must not list a type no notification class declares.',
        );
    }

    #[Test]
    public function every_declared_notification_type_is_in_the_catalogue(): void
    {
        $missing = [];

        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\' . basename($file, '.php');

            if (! class_exists($class) || ! method_exists($class, 'getNotificationType')) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $type = $reflection->newInstanceWithoutConstructor()->getNotificationType();

            if (! array_key_exists($type, NotificationCatalogue::all())) {
                $missing[] = $type;
            }
        }

        $this->assertSame([], $missing, 'A sent notification type with no catalogue entry has no toggle and no category.');
    }

    #[Test]
    public function the_preferences_endpoint_offers_the_real_types(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $categories = $this->getJson('/api/v1/mobile/settings/notifications')->assertOk()->json('categories');

        $this->assertSame(NotificationCatalogue::configurableTypes(), array_keys($categories));
        $this->assertArrayHasKey('integration_failed', $categories);
        $this->assertArrayHasKey('cookie_expiry_warning', $categories);
    }

    #[Test]
    public function the_retired_categories_are_no_longer_offered(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $categories = $this->getJson('/api/v1/mobile/settings/notifications')->assertOk()->json('categories');

        foreach (['anomaly', 'digest', 'new_bookmark', 'calendar_event'] as $retired) {
            $this->assertArrayNotHasKey($retired, $categories, "{$retired} gates a notification that is never sent.");
        }
    }

    #[Test]
    public function a_toggle_saved_over_the_api_actually_gates_delivery(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $etag = $this->getJson('/api/v1/mobile/settings/notifications')->headers->get('ETag');

        // The endpoint requires every toggle when the mode is not work_hours,
        // so send the full set with one flipped off.
        $categories = array_fill_keys(NotificationCatalogue::configurableTypes(), true);
        $categories['integration_failed'] = false;

        $this->withHeader('If-Match', $etag)
            ->patchJson('/api/v1/mobile/settings/notifications', [
                'delivery_mode' => 'immediate',
                'categories' => $categories,
            ])
            ->assertSuccessful();

        // SparkNotification::via() gates on the real type string, so this is
        // the assertion the invented categories could never satisfy.
        $this->assertFalse(
            $this->user->fresh()->hasPushNotificationsEnabledForType('integration_failed'),
        );
    }

    #[Test]
    public function a_failure_notification_uses_a_category_the_client_registers(): void
    {
        $this->assertSame(
            'INTEGRATION_STATUS',
            $this->categoryFor('integration_failed'),
        );
    }

    #[Test]
    public function a_reauthorization_notification_uses_the_attention_category(): void
    {
        $this->assertSame(
            'INTEGRATION_ATTENTION',
            $this->categoryFor('integration_authentication_failed'),
        );
        $this->assertSame(
            'INTEGRATION_ATTENTION',
            $this->categoryFor('cookie_expiry_warning'),
        );
    }

    #[Test]
    public function every_mapped_category_is_one_the_client_registered(): void
    {
        // Kept in step with SparkApp.swift registerNotificationCategories().
        // A category identifier the client has not registered shows no action
        // buttons, and matching is case-sensitive.
        $registeredByClient = ['INTEGRATION_ATTENTION', 'INTEGRATION_STATUS', 'SYSTEM'];

        $mapped = NotificationCatalogue::apnsCategories();

        $this->assertNotEmpty($mapped);
        $this->assertSame(
            [],
            array_diff(array_values($mapped), $registeredByClient),
        );
        $this->assertSame(
            [],
            array_diff($registeredByClient, NotificationCatalogue::apnsCategoryIdentifiers()),
            'The client registers a category no notification is ever sent with.',
        );
    }

    #[Test]
    public function every_catalogue_type_carries_a_category(): void
    {
        foreach (array_keys(NotificationCatalogue::all()) as $type) {
            $this->assertNotNull(
                $this->categoryFor($type),
                "{$type} would arrive with no action buttons.",
            );
        }
    }

    private function notifyUser(): string
    {
        $this->user->notify(new SystemMaintenance('Scheduled maintenance', 'Back shortly.'));

        return (string) $this->user->notifications()->latest()->firstOrFail()->id;
    }

    private function categoryFor(string $notificationType): ?string
    {
        return NotificationCatalogue::apnsCategories()[$notificationType] ?? null;
    }
}
