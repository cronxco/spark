<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
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
            ->assertJsonPath('id', $this->integration->id)
            ->assertJsonPath('service', 'monzo');
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

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/sync")
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

        $this->postJson("/api/v1/mobile/integrations/{$this->integration->id}/sync")
            ->assertStatus(422);
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
}
