<?php

namespace Tests\Feature\Cards;

use App\Cards\Cards\Day\CheckinHistoryCard;
use App\Cards\Cards\Day\DayIntroCard;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DayCardsTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function checkin_history_files_a_checkin_under_the_day_it_was_for(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'daily_checkin']);

        // Submitted at 00:30 BST on 1 July, which is still 30 June in UTC.
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'daily_checkin',
            'action' => 'had_morning_checkin',
            'source_id' => 'daily_checkin_morning_2026-07-01',
            'time' => '2026-06-30 23:30:00',
            'value' => 7,
            'value_multiplier' => 1,
            'event_metadata' => ['period' => 'morning', 'date' => '2026-07-01', 'physical_energy' => 3, 'mental_energy' => 4],
        ]);

        $data = (new CheckinHistoryCard)->getData($user, '2026-07-01');
        $days = collect($data['history'])->keyBy('date');

        $this->assertCount(30, $data['history']);
        $this->assertSame('2026-07-01', $data['endDate']);
        $this->assertSame(3, $days['2026-07-01']['morning']['physical']);
        $this->assertNull($days['2026-06-30']['morning']);
    }

    #[Test]
    public function day_intro_uses_the_users_local_today(): void
    {
        // 00:30 UTC on 2 July is 17:30 on 1 July in Los Angeles.
        Carbon::setTestNow('2026-07-02 00:30:00 UTC');
        $user = User::factory()->create(['settings' => ['timezone' => 'America/Los_Angeles']]);
        $now = Carbon::now('America/Los_Angeles');
        $card = new DayIntroCard;

        $this->assertTrue($card->isEligible($now, $user, '2026-07-01'));
        $this->assertFalse($card->isEligible($now, $user, '2026-07-02'));
    }
}
