<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use App\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationSettingsControllerTest extends TestCase
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
    public function show_requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/settings/notifications')->assertStatus(401);
    }

    #[Test]
    public function show_returns_default_mobile_notification_preferences(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/settings/notifications')
            ->assertOk()
            ->assertExactJson($this->payload());
    }

    #[Test]
    public function show_maps_existing_notification_preferences_to_mobile_contract(): void
    {
        $overrides = [
            'integration_completed' => false,
            'integration_failed' => false,
            'cookie_expiry_warning' => false,
        ];

        $this->user->updateNotificationPreferences([
            'push_types' => $overrides,
            'delayed_sending' => [
                'mode' => 'daily_digest',
                'digest_time' => '07:30',
            ],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/settings/notifications')
            ->assertOk()
            ->assertExactJson($this->payload([
                'categories' => $overrides,
                'delivery_mode' => 'daily_digest',
                'digest_time' => '07:30',
            ]));
    }

    #[Test]
    public function update_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->patchJson('/api/v1/mobile/settings/notifications', $this->payload())
            ->assertStatus(403);
    }

    #[Test]
    public function update_saves_mobile_notification_preferences(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $payload = $this->payload([
            'categories' => [
                'integration_completed' => false,
                'migration_failed' => false,
                'system_maintenance' => false,
            ],
            'delivery_mode' => 'daily_digest',
            'digest_time' => '06:45',
        ]);

        $this->patchJson('/api/v1/mobile/settings/notifications', $payload, $this->ifMatchUser())
            ->assertOk()
            ->assertExactJson($payload);

        $this->assertSame($payload['categories'], $this->user->fresh()->settings['notifications']['push_types']);
        $this->assertSame([
            'mode' => 'daily_digest',
            'digest_time' => '06:45',
        ], $this->user->fresh()->settings['notifications']['delayed_sending']);
    }

    #[Test]
    public function update_validates_contract_shape(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        // Every configurable type but the one supplied must be reported
        // missing, so the contract is derived from the catalogue rather than
        // hand-listed — that hand-list is how it fell behind in the first place.
        $missing = array_map(
            fn (string $type) => "categories.{$type}",
            array_values(array_diff(NotificationCatalogue::configurableTypes(), ['integration_failed'])),
        );

        $this->patchJson('/api/v1/mobile/settings/notifications', [
            'categories' => ['integration_failed' => true],
            'delivery_mode' => 'later',
            'digest_time' => '25:00',
        ], $this->ifMatchUser())->assertStatus(422)
            ->assertJsonValidationErrors([
                ...$missing,
                'delivery_mode',
                'digest_time',
            ]);
    }

    /**
     * The contract's default shape, derived from NotificationCatalogue so this
     * test cannot fall behind the types the application actually sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'categories' => array_fill_keys(NotificationCatalogue::configurableTypes(), true),
            'delivery_mode' => 'immediate',
            'digest_time' => '08:00',
        ], $overrides);
    }

    /** @return array{If-Match: string} */
    private function ifMatchUser(): array
    {
        return ['If-Match' => $this->getJson('/api/v1/mobile/settings/notifications')->headers->get('ETag')];
    }
}
