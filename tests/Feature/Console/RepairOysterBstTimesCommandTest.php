<?php

namespace Tests\Feature\Console;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairOysterBstTimesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Integration $integration;

    private string $cutoff = '2026-10-12 18:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-12 20:00:00');

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'oyster']);
        $this->integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oyster',
            'instance_type' => 'journeys',
        ]);
    }

    #[Test]
    public function it_requires_a_cutoff(): void
    {
        $this->artisan('oyster:repair-bst-times')->assertFailed();
    }

    #[Test]
    public function it_rejects_a_future_cutoff(): void
    {
        $this->artisan('oyster:repair-bst-times', ['--before' => '2026-10-13 09:00:00'])->assertFailed();
    }

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $event = $this->makeEvent('2026-10-10 14:13:00');

        $this->artisan('oyster:repair-bst-times', ['--before' => $this->cutoff])->assertSuccessful();

        $this->assertSame('2026-10-10T14:13:00Z', $event->fresh()->time->utc()->toIso8601ZuluString());
    }

    #[Test]
    public function apply_shifts_bst_events_back_an_hour_and_leaves_winter_alone(): void
    {
        $summer = $this->makeEvent('2026-10-10 14:13:00');
        $winter = $this->makeEvent('2026-11-28 17:47:00');

        $this->artisan('oyster:repair-bst-times', ['--before' => $this->cutoff, '--apply' => true])->assertSuccessful();

        $this->assertSame('2026-10-10T13:13:00Z', $summer->fresh()->time->utc()->toIso8601ZuluString());
        $this->assertSame('2026-11-28T17:47:00Z', $winter->fresh()->time->utc()->toIso8601ZuluString());
    }

    #[Test]
    public function it_skips_events_updated_after_the_cutoff_and_is_idempotent(): void
    {
        $reimported = $this->makeEvent('2026-10-10 13:13:00', '2026-10-12 19:00:00');
        $legacy = $this->makeEvent('2026-10-10 14:13:00');

        $options = ['--before' => $this->cutoff, '--apply' => true];
        $this->artisan('oyster:repair-bst-times', $options)->assertSuccessful();
        $this->artisan('oyster:repair-bst-times', $options)->assertSuccessful();

        $this->assertSame('2026-10-10T13:13:00Z', $reimported->fresh()->time->utc()->toIso8601ZuluString());
        $this->assertSame('2026-10-10T13:13:00Z', $legacy->fresh()->time->utc()->toIso8601ZuluString());
    }

    private function makeEvent(string $storedUtc, string $updatedAt = '2026-10-12 17:14:00'): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oyster',
            'time' => $storedUtc,
            'updated_at' => $updatedAt,
        ]);
    }
}
