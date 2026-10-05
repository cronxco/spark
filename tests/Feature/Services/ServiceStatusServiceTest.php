<?php

namespace Tests\Feature\Services;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\Api\ServiceStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function apple_health_freshness_uses_updated_at_not_midnight_event_time(): void
    {
        Carbon::setTestNow('2026-09-20 12:00:00');
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'apple_health',
        ]);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'apple_health',
            'domain' => 'health',
            'action' => 'had_step_count',
            'time' => Carbon::parse('2026-09-20 00:00:00', 'Europe/London')->utc(),
            'updated_at' => now()->subMinutes(5),
        ]);

        $status = app(ServiceStatusService::class)->forDay($user, Carbon::parse('2026-09-20'));

        $this->assertSame('complete', $status['services']['apple_health']['coverage']);
        $this->assertSame('updated_at', $status['services']['apple_health']['freshness_basis']);
        $this->assertSame(now()->subMinutes(5)->toIso8601String(), $status['services']['apple_health']['last_updated_at']);
    }

    #[Test]
    public function day_boundaries_are_resolved_in_the_users_timezone(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'apple_health']);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'apple_health',
            'domain' => 'health',
            'time' => Carbon::parse('2026-07-01 00:30:00', 'Europe/London')->utc(),
        ]);

        $status = app(ServiceStatusService::class)->forDay($user, Carbon::parse('2026-07-01'));

        $this->assertSame(1, $status['total_events']);
        $this->assertSame('Europe/London', $status['timezone']);
    }
}
