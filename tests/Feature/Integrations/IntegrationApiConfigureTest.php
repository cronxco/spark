<?php

namespace Tests\Feature\Integrations;

use App\Models\Integration;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * INT-08: the REST configure endpoint validated against the plugin's top-level
 * schema rather than the instance type's, and replaced the whole configuration
 * with the validated fields, dropping `paused`, schedules and stored keys.
 */
class IntegrationApiConfigureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function configuring_one_field_keeps_the_rest_of_the_configuration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'instance_type' => 'workouts',
            'configuration' => ['api_key' => 'hevy-key', 'paused' => true, 'update_frequency_minutes' => 60],
        ]);
        Sanctum::actingAs($user, ['integrations:manage']);

        $this->withHeader('If-Match', app(ResourceVersion::class)->etag($integration))->patchJson("/api/v1/integrations/{$integration->id}/configure", ['update_frequency_minutes' => 30])
            ->assertOk();

        $configuration = $integration->fresh()->configuration;
        $this->assertSame(30, $configuration['update_frequency_minutes']);
        $this->assertSame('hevy-key', $configuration['api_key']);
        $this->assertTrue($configuration['paused']);
    }

    #[Test]
    public function the_instance_types_own_fields_are_accepted(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'outline',
            'instance_type' => 'task',
            'configuration' => [
                'api_url' => 'https://docs.example.com',
                'access_token' => 'outline-token',
                'daynotes_collection_id' => 'collection',
                'poll_interval_minutes' => 15,
            ],
        ]);
        Sanctum::actingAs($user, ['integrations:manage']);

        $this->withHeader('If-Match', app(ResourceVersion::class)->etag($integration))->patchJson("/api/v1/integrations/{$integration->id}/configure", ['task_mode' => 'job'])
            ->assertOk();

        $this->assertSame('job', $integration->fresh()->configuration['task_mode'] ?? null);
        $this->assertSame('outline-token', $integration->fresh()->configuration['access_token']);
    }

    #[Test]
    public function another_users_integration_cannot_be_configured(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => User::factory()->create()->id,
            'service' => 'hevy',
            'instance_type' => 'workouts',
        ]);
        Sanctum::actingAs(User::factory()->create(), ['integrations:manage']);

        $this->withHeader('If-Match', app(ResourceVersion::class)->etag($integration))->patchJson("/api/v1/integrations/{$integration->id}/configure", ['update_frequency_minutes' => 30])
            ->assertNotFound();
    }

    #[Test]
    public function configuration_requires_management_scope_and_current_version(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'hevy', 'instance_type' => 'workouts']);
        $url = "/api/v1/integrations/{$integration->id}/configure";
        Sanctum::actingAs($user, ['integrations:read']);
        $this->patchJson($url, [])->assertForbidden();
        Sanctum::actingAs($user, ['integrations:manage']);
        $this->patchJson($url, [])->assertStatus(428);
        $this->withHeader('If-Match', '"stale"')->patchJson($url, [])->assertStatus(412);
    }
    #[Test]
    public function invalid_select_number_and_range_values_do_not_change_configuration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'instance_type' => 'workouts',
            'configuration' => ['api_key' => 'hevy-key', 'units' => 'kg', 'update_frequency_minutes' => 60],
        ]);
        $before = $integration->configuration;
        Sanctum::actingAs($user, ['integrations:manage']);

        $this->withHeader('If-Match', app(ResourceVersion::class)->etag($integration))
            ->patchJson("/api/v1/integrations/{$integration->id}/configure", [
                'units' => 'oz',
                'update_frequency_minutes' => 1,
                'weight_increment_kg' => ['invalid'],
            ])->assertUnprocessable()->assertJsonValidationErrors(['units', 'update_frequency_minutes', 'weight_increment_kg']);

        $this->assertSame($before, $integration->fresh()->configuration);
    }

}
