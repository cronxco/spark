<?php

namespace Tests\Feature\Flint;

use App\Models\User;
use App\Services\Flint\FlintScheduleService;
use App\Services\Flint\RoutineConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintScheduleCadenceTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $flint = []): User
    {
        return User::factory()->create(['settings' => ['flint' => $flint]]);
    }

    private function service(): FlintScheduleService
    {
        return app(FlintScheduleService::class);
    }

    #[Test]
    public function routines_without_a_schedule_run_every_day_at_their_legacy_time(): void
    {
        $user = $this->user(['reading_list_time' => '18:15']);

        $this->assertNull($this->service()->cadence($user, 'reading_list'));
        $this->assertTrue($this->service()->occursOn($user, 'reading_list', Carbon::parse('2026-10-13')));
        $this->assertSame('18:15', $this->service()->slot($user, 'reading_list', Carbon::parse('2026-10-13')));
    }

    #[Test]
    public function money_weekly_defaults_to_sundays_and_money_close_to_the_second(): void
    {
        $user = $this->user();

        $this->assertTrue($this->service()->occursOn($user, 'money_weekly', Carbon::parse('2026-10-11')));
        $this->assertFalse($this->service()->occursOn($user, 'money_weekly', Carbon::parse('2026-10-12')));
        $this->assertTrue($this->service()->occursOn($user, 'money_close', Carbon::parse('2026-11-02')));
        $this->assertFalse($this->service()->occursOn($user, 'money_close', Carbon::parse('2026-11-03')));
        $this->assertSame('09:00', $this->service()->slot($user, 'money_close', Carbon::parse('2026-11-02')));
    }

    #[Test]
    public function a_user_schedule_replaces_the_default_and_can_set_its_own_time(): void
    {
        $user = $this->user(['schedules' => [
            'money_weekly' => ['frequency' => 'weekly', 'days' => ['mon', 'thu'], 'time' => '08:00', 'weekend_time' => '10:00'],
        ]]);

        $this->assertTrue($this->service()->occursOn($user, 'money_weekly', Carbon::parse('2026-10-12')));
        $this->assertTrue($this->service()->occursOn($user, 'money_weekly', Carbon::parse('2026-10-15')));
        $this->assertFalse($this->service()->occursOn($user, 'money_weekly', Carbon::parse('2026-10-11')));
        $this->assertSame('08:00', $this->service()->slot($user, 'money_weekly', Carbon::parse('2026-10-12')));
    }

    #[Test]
    public function weekdays_and_monthly_rules(): void
    {
        $user = $this->user(['schedules' => [
            'topics' => ['frequency' => 'weekdays', 'weekend_time' => '11:00'],
            'money_close' => ['frequency' => 'monthly', 'day_of_month' => 'last'],
            'news_roundup' => ['frequency' => 'monthly', 'day_of_month' => 'first_working'],
            'reading_list' => ['frequency' => 'monthly', 'day_of_month' => 31],
        ]]);
        $service = $this->service();

        $this->assertTrue($service->occursOn($user, 'topics', Carbon::parse('2026-10-09')));
        $this->assertFalse($service->occursOn($user, 'topics', Carbon::parse('2026-10-10')));
        $this->assertTrue($service->occursOn($user, 'money_close', Carbon::parse('2026-02-28')));
        $this->assertFalse($service->occursOn($user, 'money_close', Carbon::parse('2026-02-27')));
        $this->assertTrue($service->occursOn($user, 'news_roundup', Carbon::parse('2026-11-02')));
        $this->assertFalse($service->occursOn($user, 'news_roundup', Carbon::parse('2026-11-01')));
        $this->assertTrue($service->occursOn($user, 'reading_list', Carbon::parse('2026-02-28')));
    }

    #[Test]
    public function a_malformed_schedule_falls_back_to_daily_and_bad_times_are_ignored(): void
    {
        $user = $this->user(['topics_time' => '21:00', 'schedules' => ['topics' => ['frequency' => 'fortnightly', 'time' => '25:99']]]);

        $this->assertNull($this->service()->cadence($user, 'topics'));
        $this->assertTrue($this->service()->occursOn($user, 'topics', Carbon::parse('2026-10-14')));
        $this->assertSame('21:00', $this->service()->slot($user, 'topics', Carbon::parse('2026-10-14')));
    }

    #[Test]
    public function next_eligible_run_skips_days_the_routine_does_not_occur_on(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 12:00', 'UTC'));
        $user = $this->user(['money_close_enabled' => true]);

        $next = $this->service()->nextEligibleRun($user, 'money_close');

        $this->assertSame('2026-11-02', $next->toDateString());
    }

    #[Test]
    public function both_money_routines_map_to_one_skill_and_resolve_by_name(): void
    {
        $this->assertSame('flint-money', RoutineConfig::SKILLS['money_weekly']);
        $this->assertSame('flint-money', RoutineConfig::SKILLS['money_close']);
        $this->assertSame('money_close', RoutineConfig::routineFor('money_close'));
        $this->assertSame('money_weekly', RoutineConfig::routineFor('flint-money'));
        $this->assertNull(RoutineConfig::digestKind('money_weekly'));
    }
}
