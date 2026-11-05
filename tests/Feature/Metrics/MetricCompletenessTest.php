<?php

namespace Tests\Feature\Metrics;

use App\Services\Metrics\MetricCompleteness;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetricCompletenessTest extends TestCase
{
    #[Test]
    public function a_total_that_fills_in_is_final_after_the_day_ends_and_settles(): void
    {
        $completeness = app(MetricCompleteness::class);
        $day = Carbon::parse('2026-11-04 00:00:00', 'UTC');

        // Oura heart rate settles 6 hours after its day ends.
        $this->assertFalse($completeness->isFinal('oura', 'had_heart_rate', $day, 'UTC', Carbon::parse('2026-11-05 05:59:00', 'UTC')));
        $this->assertTrue($completeness->isFinal('oura', 'had_heart_rate', $day, 'UTC', Carbon::parse('2026-11-05 06:01:00', 'UTC')));
    }

    #[Test]
    public function west_of_utc_the_day_ends_locally(): void
    {
        $completeness = app(MetricCompleteness::class);
        $day = Carbon::parse('2026-11-05 00:00:00', 'UTC');

        // 20:00 in Los Angeles on the 5th is 04:00 UTC on the 6th, but the local day has not ended.
        $now = Carbon::parse('2026-11-05 20:00:00', 'America/Los_Angeles');

        $this->assertFalse($completeness->isFinal('apple_health', 'had_step_count', $day, 'America/Los_Angeles', $now));
    }

    #[Test]
    public function a_reading_is_final_once_its_source_has_stopped_rewriting_it(): void
    {
        $completeness = app(MetricCompleteness::class);
        $day = Carbon::parse('2026-11-05 00:00:00', 'UTC');
        $updated = Carbon::parse('2026-11-05 07:00:00', 'UTC');

        // Readiness settles after 6 quiet hours, but not at the end of the day.
        $this->assertFalse($completeness->isFinal('oura', 'had_readiness_score', $day, 'UTC', Carbon::parse('2026-11-05 12:00:00', 'UTC'), $updated));
        $this->assertTrue($completeness->isFinal('oura', 'had_readiness_score', $day, 'UTC', Carbon::parse('2026-11-05 13:30:00', 'UTC'), $updated));
    }

    #[Test]
    public function a_metric_that_is_not_listed_is_final_at_once(): void
    {
        $day = Carbon::parse('2026-11-05 00:00:00', 'UTC');

        $this->assertTrue(app(MetricCompleteness::class)->isFinal('hevy', 'completed_workout', $day, 'UTC', Carbon::parse('2026-11-05 00:01:00', 'UTC')));
    }
}
