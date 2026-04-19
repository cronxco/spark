<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
