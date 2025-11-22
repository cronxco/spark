<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_has_uuid_as_primary_key(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $this->assertTrue(Str::isUuid($integration->id));
    }

    public function test_integration_id_is_not_auto_incrementing(): void
    {
        $integration = new Integration();

        $this->assertFalse($integration->incrementing);
        $this->assertEquals('string', $integration->getKeyType());
    }

    public function test_integration_uuid_is_generated_on_creation(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $this->assertNotNull($integration->id);
        $this->assertTrue(Str::isUuid($integration->id));
    }

    public function test_integration_does_not_override_provided_id(): void
    {
        $user = User::factory()->create();
        $customId = Str::uuid()->toString();

        $integration = Integration::factory()->create([
            'id' => $customId,
            'user_id' => $user->id,
        ]);

        $this->assertEquals($customId, $integration->id);
    }

    public function test_integration_has_fillable_attributes(): void
    {
        $integration = new Integration();
        $fillable = $integration->getFillable();

        $expectedFillable = [
            'user_id', 'service', 'name', 'account_id',
            'access_token', 'refresh_token', 'expiry', 'refresh_expiry',
        ];

        foreach ($expectedFillable as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function test_integration_casts_expiry_to_datetime(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $integration->expiry);
    }

    public function test_integration_casts_refresh_expiry_to_datetime(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $integration->refresh_expiry);
    }

    public function test_integration_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $integration->user);
        $this->assertEquals($user->id, $integration->user->id);
    }

    public function test_integration_uses_integrations_table(): void
    {
        $integration = new Integration();

        $this->assertEquals('integrations', $integration->getTable());
    }

    public function test_multiple_integrations_have_unique_uuids(): void
    {
        $user = User::factory()->create();
        $integration1 = Integration::factory()->create(['user_id' => $user->id]);
        $integration2 = Integration::factory()->create(['user_id' => $user->id]);

        $this->assertNotEquals($integration1->id, $integration2->id);
    }

    public function test_integration_can_store_service_info(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'My GitHub Integration',
            'account_id' => 'account-123',
        ]);

        $this->assertEquals('github', $integration->service);
        $this->assertEquals('My GitHub Integration', $integration->name);
        $this->assertEquals('account-123', $integration->account_id);
    }

    public function test_integration_stores_tokens(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
        ]);

        $this->assertEquals('test-access-token', $integration->access_token);
        $this->assertEquals('test-refresh-token', $integration->refresh_token);
    }

    public function test_user_can_have_multiple_integrations(): void
    {
        $user = User::factory()->create();

        Integration::factory()->count(3)->create(['user_id' => $user->id]);

        // Retrieve integrations manually since User model doesn't have integrations relationship defined
        $integrations = Integration::where('user_id', $user->id)->get();

        $this->assertCount(3, $integrations);
    }
}
