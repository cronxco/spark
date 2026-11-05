<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Helpers\DateParser;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DateParserTest extends TestCase
{
    use DateParser;

    #[Test]
    public function parses_today(): void
    {
        $date = $this->parseDate('today');

        $this->assertNotNull($date);
        $this->assertTrue($date->isSameDay(Carbon::today()));
    }

    #[Test]
    public function parses_yesterday(): void
    {
        $date = $this->parseDate('yesterday');

        $this->assertNotNull($date);
        $this->assertTrue($date->isSameDay(Carbon::yesterday()));
    }

    #[Test]
    public function parses_tomorrow(): void
    {
        $date = $this->parseDate('tomorrow');

        $this->assertNotNull($date);
        $this->assertTrue($date->isSameDay(Carbon::tomorrow()));
    }

    #[Test]
    public function parses_iso_date(): void
    {
        $date = $this->parseDate('2026-01-15');

        $this->assertNotNull($date);
        $this->assertEquals('2026-01-15', $date->toDateString());
    }

    #[Test]
    public function parses_days_ago_keyword(): void
    {
        $date = $this->parseDate('7_days_ago');

        $this->assertNotNull($date);
        $this->assertTrue($date->isSameDay(Carbon::today()->subDays(7)));
    }

    #[Test]
    public function returns_null_for_invalid_date(): void
    {
        $date = $this->parseDate('not-a-date');

        $this->assertNull($date);
    }

    #[Test]
    public function parses_multiple_dates(): void
    {
        $dates = $this->parseDates(['today', 'yesterday', 'invalid']);

        $this->assertCount(2, $dates);
        $this->assertTrue($dates[0]->isSameDay(Carbon::today()));
        $this->assertTrue($dates[1]->isSameDay(Carbon::yesterday()));
    }

    #[Test]
    public function parses_date_range_with_keywords(): void
    {
        $range = $this->parseDateRange('last_7_days', null);

        $this->assertNotNull($range);
        [$start, $end] = $range;
        $this->assertTrue($start->isSameDay(Carbon::today()->subDays(6)));
        $this->assertTrue($end->isSameDay(Carbon::today()));
    }

    #[Test]
    public function parses_date_range_with_explicit_dates(): void
    {
        $range = $this->parseDateRange('2026-01-01', '2026-01-31');

        $this->assertNotNull($range);
        [$start, $end] = $range;
        $this->assertEquals('2026-01-01', $start->toDateString());
        $this->assertEquals('2026-01-31', $end->toDateString());
    }

    #[Test]
    public function parses_this_week_range(): void
    {
        $range = $this->parseDateRange('this_week', null);

        $this->assertNotNull($range);
        [$start, $end] = $range;
        $this->assertTrue($start->isSameDay(Carbon::today()->startOfWeek()));
        $this->assertTrue($end->isSameDay(Carbon::today()));
    }

    #[Test]
    public function parses_this_month_range(): void
    {
        $range = $this->parseDateRange('this_month', null);

        $this->assertNotNull($range);
        [$start, $end] = $range;
        $this->assertTrue($start->isSameDay(Carbon::today()->startOfMonth()));
        $this->assertTrue($end->isSameDay(Carbon::today()));
    }

    #[Test]
    public function returns_null_for_empty_date_range(): void
    {
        $range = $this->parseDateRange(null, null);

        $this->assertNull($range);
    }

    #[Test]
    public function date_parser_is_case_insensitive(): void
    {
        $date = $this->parseDate('TODAY');

        $this->assertNotNull($date);
        $this->assertTrue($date->isSameDay(Carbon::today()));
    }
}
