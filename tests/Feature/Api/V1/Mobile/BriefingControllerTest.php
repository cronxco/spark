<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BriefingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/briefing/today')->assertStatus(401);
    }

    #[Test]
    public function returns_summary_shape_for_today(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today')
            ->assertOk()
            ->assertJsonStructure(['sections', 'anomalies', 'sync_status'])
            ->assertHeader('ETag')
            ->assertHeader('Last-Modified');
    }

    #[Test]
    public function rejects_malformed_date(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today?date=not-a-date')
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_array_date_param(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today?date[]=2024-01-01')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid date.');
    }

    #[Test]
    public function rejects_array_domains_param(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today?domains[]=health')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid domains.');
    }

    #[Test]
    public function rejects_sloppy_iso_date_format(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        // Carbon::parse would accept these; createFromFormat('Y-m-d') must not.
        $this->getJson('/api/v1/mobile/briefing/today?date=2024-1-1')->assertStatus(422);
        $this->getJson('/api/v1/mobile/briefing/today?date=2024-13-01')->assertStatus(422);
    }

    #[Test]
    public function etag_returns_304_on_match(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $first = $this->getJson('/api/v1/mobile/briefing/today')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->getJson('/api/v1/mobile/briefing/today', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }
}
