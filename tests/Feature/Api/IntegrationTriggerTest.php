<?php

namespace Tests\Feature\Api;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationTriggerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_users_cannot_trigger_integrations(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'instance_type' => 'sleep',
        ]);

        $this->postJson("/api/integrations/{$integration->id}/trigger")
            ->assertStatus(401);
    }

    #[Test]
    public function authenticated_user_can_trigger_an_integration(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'instance_type' => 'sleep',
        ]);

        $response = $this->postJson("/api/integrations/{$integration->id}/trigger");

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'integration_id', 'service', 'instance_type', 'jobs_dispatched'])
            ->assertJsonFragment([
                'integration_id' => $integration->id,
                'service' => 'oura',
                'instance_type' => 'sleep',
            ]);
    }

    #[Test]
    public function triggering_a_paused_integration_returns_422(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'instance_type' => 'sleep',
            'configuration' => ['paused' => true],
        ]);

        $this->postJson("/api/integrations/{$integration->id}/trigger")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Integration is paused.']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function user_cannot_trigger_another_users_integration(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $integration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'oura',
            'instance_type' => 'sleep',
        ]);

        $this->postJson("/api/integrations/{$integration->id}/trigger")
            ->assertStatus(404);

        Queue::assertNothingPushed();
    }
}
