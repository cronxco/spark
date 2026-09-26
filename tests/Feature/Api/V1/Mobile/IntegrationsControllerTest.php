<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'name' => 'Personal Monzo',
        ]);
    }

    #[Test]
    public function index_requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/integrations')->assertStatus(401);
    }

    #[Test]
    public function index_returns_users_integrations(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/integrations')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'service', 'name']]])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.service', 'monzo');
    }

    #[Test]
    public function show_returns_integration_for_owner(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/integrations/{$this->integration->id}")
            ->assertOk()
            ->assertJsonStructure([
                'integration' => ['id', 'service', 'name', 'instance_type', 'status', 'domain', 'paused', 'last_sync_at', 'next_update_at'],
                'last_sync_at',
                'recent_events',
                'domain',
                'status_message',
                'supports_reauth',
                'oauth_start_url',
            ])
            ->assertJsonPath('integration.id', $this->integration->id)
            ->assertJsonPath('integration.service', 'monzo')
            ->assertJsonPath('domain', 'money')
            ->assertJsonPath('supports_reauth', true);
    }

    #[Test]
    public function show_includes_the_latest_events_for_the_integration(): void
    {
        $this->makeEvent($this->integration, now()->subHours(2));
        $latest = $this->makeEvent($this->integration, now()->subMinutes(5));
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/integrations/{$this->integration->id}")
            ->assertOk()
            ->assertJsonCount(2, 'recent_events')
            ->assertJsonPath('recent_events.0.id', $latest->id);
    }

    #[Test]
    public function show_does_not_offer_reauth_for_manual_integrations(): void
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'daily_checkin']);
        $manual = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'daily_checkin',
        ]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/integrations/{$manual->id}")
            ->assertOk()
            ->assertJsonPath('supports_reauth', false)
            ->assertJsonPath('oauth_start_url', null);
    }

    #[Test]
    public function index_reports_a_derived_status_for_each_integration(): void
    {
        $this->integration->update(['last_successful_update_at' => now()->subMinute()]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/integrations')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'up_to_date')
            ->assertJsonPath('data.0.paused', false);
    }

    #[Test]
    public function index_reports_paused_integrations_as_paused(): void
    {
        $this->integration->update(['configuration' => ['paused' => true]]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/integrations')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'paused')
            ->assertJsonPath('data.0.paused', true);
    }

    #[Test]
    public function index_reports_quiet_push_integrations_as_stale_not_needing_update(): void
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'daily_checkin']);
        Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'daily_checkin',
        ]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/integrations')->assertOk();

        $manual = collect($response->json('data'))->firstWhere('service', 'daily_checkin');
        $this->assertSame('stale', $manual['status']);
    }

    #[Test]
    public function show_returns_404_for_other_users_integration(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/integrations/{$this->integration->id}")
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/mobile/integrations/{id}/sync
    // -------------------------------------------------------------------------

    #[Test]
    public function sync_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/sync")
            ->assertStatus(403);
    }

    #[Test]
    public function sync_triggers_a_fetch_for_the_owner(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/sync", [], $this->ifMatch($this->integration))
            ->assertOk()
            ->assertJsonStructure(['message', 'jobs_dispatched']);
    }

    #[Test]
    public function sync_returns_404_for_other_users_integration(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/sync")
            ->assertStatus(404);
    }

    #[Test]
    public function sync_returns_422_when_paused(): void
    {
        $this->integration->update(['configuration' => ['paused' => true]]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/sync", [], $this->ifMatch($this->integration))
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/mobile/integrations/{id}/pause
    // -------------------------------------------------------------------------

    #[Test]
    public function pause_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/pause", ['paused' => true])
            ->assertStatus(403);
    }

    #[Test]
    public function pause_requires_if_match(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/pause", ['paused' => true])
            ->assertStatus(428);
    }

    #[Test]
    public function pause_and_resume_toggle_the_integration(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/pause", ['paused' => true], $this->ifMatch($this->integration))
            ->assertOk()
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('paused', true)
            ->assertHeader('ETag');
        $this->assertTrue($this->integration->fresh()->isPaused());

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/pause", ['paused' => false], $this->ifMatch($this->integration->fresh()))
            ->assertOk()
            ->assertJsonPath('paused', false);
        $this->assertFalse($this->integration->fresh()->isPaused());
    }

    #[Test]
    public function pause_validates_the_flag(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/pause", ['paused' => 'sometimes'], $this->ifMatch($this->integration))
            ->assertStatus(422)
            ->assertJsonValidationErrors('paused');
    }

    #[Test]
    public function pause_returns_404_for_other_users_integration(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/pause", ['paused' => true], ['If-Match' => '"x"'])
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/mobile/integrations/{id}/oauth/start
    // -------------------------------------------------------------------------

    #[Test]
    public function oauth_start_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/oauth/start")
            ->assertStatus(403);
    }

    #[Test]
    public function oauth_start_returns_a_url_and_flags_the_group(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/oauth/start")
            ->assertOk()
            ->assertJsonStructure(['url']);

        $this->assertStringStartsWith('https://auth.monzo.com', $response->json('url'));

        $group = $this->integration->group()->first();
        $this->assertTrue($group->auth_metadata['mobile_reauth_origin'] ?? false);
    }

    #[Test]
    public function oauth_start_returns_404_for_other_users_integration(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/oauth/start")
            ->assertStatus(404);
    }

    #[Test]
    public function oauth_start_returns_422_for_non_oauth_integration(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'daily_checkin',
        ]);
        $manual = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'daily_checkin',
        ]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/integrations/{$manual->id}/oauth/start")
            ->assertStatus(422);
    }

    protected function makeEvent(Integration $integration, Carbon $time): Event
    {
        return Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => $time,
        ]);
    }

    /** @return array{If-Match: string} */
    protected function ifMatch(Integration $integration): array
    {
        return ['If-Match' => $this->getJson("/api/v1/mobile/integrations/{$integration->id}")->headers->get('ETag')];
    }
}
