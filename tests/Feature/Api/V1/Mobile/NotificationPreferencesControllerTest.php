<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationPreferencesControllerTest extends TestCase
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
    public function show_returns_mobile_notification_preferences(): void
    {
        $this->user->updateNotificationPreferences([
            'push_types' => [
                'anomaly' => false,
                'integration_failed' => true,
            ],
            'delayed_sending' => [
                'mode' => 'daily_digest',
                'digest_time' => '08:30',
            ],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/settings/notifications')
            ->assertOk()
            ->assertJsonPath('categories.anomaly', false)
            ->assertJsonPath('categories.integration_failed', true)
            ->assertJsonPath('categories.digest', true)
            ->assertJsonPath('delivery_mode', 'daily_digest')
            ->assertJsonPath('digest_time', '08:30');
    }

    #[Test]
    public function update_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->patchJson('/api/v1/mobile/settings/notifications', $this->payload())
            ->assertStatus(403);
    }

    #[Test]
    public function update_stores_mobile_notification_preferences(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->patchJson('/api/v1/mobile/settings/notifications', $this->payload())
            ->assertStatus(204);

        $this->user->refresh();
        $preferences = $this->user->getNotificationPreferences();

        $this->assertFalse($preferences['push_types']['anomaly']);
        $this->assertTrue($preferences['push_types']['digest']);
        $this->assertSame('work_hours', $preferences['delayed_sending']['mode']);
        $this->assertSame('10:15', $preferences['delayed_sending']['digest_time']);
    }

    protected function payload(): array
    {
        return [
            'categories' => [
                'anomaly' => false,
                'digest' => true,
            ],
            'delivery_mode' => 'work_hours',
            'digest_time' => '10:15',
        ];
    }
}
