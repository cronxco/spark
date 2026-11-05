<?php

namespace Tests\Feature;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormatRelativeTimeTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-09-26 12:00:00', 'UTC');
    }

    #[Test]
    public function it_is_relative_within_a_day_in_either_direction(): void
    {
        $this->assertSame('22 minutes ago', format_relative_time($this->now->copy()->subMinutes(22), null, $this->now));
        $this->assertSame('3 hours from now', format_relative_time($this->now->copy()->addHours(3), null, $this->now));
    }

    #[Test]
    public function it_is_a_weekday_and_time_within_a_week(): void
    {
        $this->assertSame('Tue 14:05', format_relative_time(Carbon::parse('2026-09-22 14:05:00', 'UTC'), null, $this->now));
    }

    #[Test]
    public function it_is_a_date_beyond_a_week_and_adds_the_year_when_it_differs(): void
    {
        $this->assertSame('12 Aug', format_relative_time(Carbon::parse('2026-08-12 09:00:00', 'UTC'), null, $this->now));
        $this->assertSame('12 Aug 2025', format_relative_time(Carbon::parse('2025-08-12 09:00:00', 'UTC'), null, $this->now));
    }
}
