<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeneralApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function general_v1_is_not_gated_by_the_ios_feature_flag(): void
    {
        config(['ios.mobile_api_enabled' => false]);
        Sanctum::actingAs(User::factory()->create(), ['insights:read']);

        $this->getJson('/api/v1/day-summary')
            ->assertOk()
            ->assertJsonStructure(['sections', 'anomalies', 'sync_status'])
            ->assertHeader('ETag');
    }

    #[Test]
    public function general_v1_requires_the_operation_capability(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['data:read']);

        $this->getJson('/api/v1/day-summary')
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'insights:read');
    }

    #[Test]
    public function general_v1_accepts_legacy_mcp_read_tokens_for_read_migration(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mcp:read']);

        $this->getJson('/api/v1/day-summary')
            ->assertOk();
    }
}
