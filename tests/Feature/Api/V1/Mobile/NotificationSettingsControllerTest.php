<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
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
        $this->user->updateNotificationPreferences([
            'push_types' => [
                'anomaly' => false,
                'digest' => true,
                'integration_failed' => false,
                'new_bookmark' => true,
                'calendar_event' => false,
            ],
            'delayed_sending' => [
                'mode' => 'daily_digest',
                'digest_time' => '07:30',
            ],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/settings/notifications')
            ->assertOk()
            ->assertExactJson($this->payload([
                'categories' => [
                    'anomaly' => false,
                    'digest' => true,
                    'integration_failed' => false,
                    'new_bookmark' => true,
                    'calendar_event' => false,
                ],
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
                'anomaly' => true,
                'digest' => false,
                'integration_failed' => true,
                'new_bookmark' => false,
                'calendar_event' => true,
            ],
            'delivery_mode' => 'daily_digest',
            'digest_time' => '06:45',
        ]);

        $this->patchJson('/api/v1/mobile/settings/notifications', $payload)
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

        $this->patchJson('/api/v1/mobile/settings/notifications', [
            'categories' => ['anomaly' => true],
            'delivery_mode' => 'later',
            'digest_time' => '25:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors([
                'categories.digest',
                'categories.integration_failed',
                'categories.new_bookmark',
                'categories.calendar_event',
                'delivery_mode',
                'digest_time',
            ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'categories' => [
                'anomaly' => true,
                'digest' => true,
                'integration_failed' => true,
                'new_bookmark' => true,
                'calendar_event' => true,
            ],
            'delivery_mode' => 'immediate',
            'digest_time' => '08:00',
        ], $overrides);
    }
}
