<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationScheduleTest extends TestCase
{
    #[Test]
    public function schedule_override_marks_due_when_never_updated()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'configuration' => [
                'use_schedule' => true,
                'schedule_times' => ['00:01'],
                'schedule_timezone' => 'UTC',
            ],
            'last_successful_update_at' => null,
        ]);

        $this->assertTrue($integration->isDue());
    }

    #[Test]
    public function schedule_override_due_after_next_run_reached()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'configuration' => [
                'use_schedule' => true,
                'schedule_times' => ['12:00'],
                'schedule_timezone' => 'UTC',
            ],
            'last_successful_update_at' => Carbon::parse('2025-01-01 11:00:00', 'UTC'),
        ]);

        $now = Carbon::parse('2025-01-01 12:01:00', 'UTC');
        $this->assertTrue($integration->isDue($now));
    }

    #[Test]
    public function paused_instances_are_not_due()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'configuration' => [
                'use_schedule' => true,
                'schedule_times' => ['12:00'],
                'schedule_timezone' => 'UTC',
                'paused' => true,
            ],
            'last_successful_update_at' => Carbon::parse('2025-01-01 11:00:00', 'UTC'),
        ]);

        $now = Carbon::parse('2025-01-01 13:00:00', 'UTC');
        $this->assertFalse($integration->isDue($now));
    }
}
