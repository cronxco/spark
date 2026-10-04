<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContextControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function day_is_marked_deprecated_in_favour_of_briefing_today(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read']);

        $this->getJson('/api/v1/mobile/context/day')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset')
            ->assertHeader('Link', '</api/v1/mobile/briefing/today>; rel="successor-version"');
    }

    #[Test]
    public function service_status_is_marked_deprecated_in_favour_of_briefing_today(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read']);

        $this->getJson('/api/v1/mobile/context/service-status')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset')
            ->assertHeader('Link', '</api/v1/mobile/briefing/today>; rel="successor-version"');
    }
}
