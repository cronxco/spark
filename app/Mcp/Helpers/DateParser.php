<?php

namespace App\Mcp\Helpers;

use Carbon\Carbon;
use Exception;

trait DateParser
{
    /**
     * Parse a single date input to a Carbon instance.
     *
     * Supports: "today", "yesterday", "tomorrow", ISO dates (YYYY-MM-DD),
     * and relative keywords like "7_days_ago", "30_days_ago".
     */
    protected function parseDate(string $input): ?Carbon
    {
        $input = strtolower(trim($input));

        return match ($input) {
            'today' => Carbon::today(),
            'yesterday' => Carbon::yesterday(),
            'tomorrow' => Carbon::tomorrow(),
            default => $this->parseRelativeOrIsoDate($input),
        };
    }

    /**
     * Parse an array of date inputs to Carbon instances.
     *
     * @param  array<string>  $inputs
     * @return array<Carbon>
     */
    protected function parseDates(array $inputs): array
    {
        $dates = [];

        foreach ($inputs as $input) {
            $date = $this->parseDate($input);
            if ($date) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Parse a date range from keywords or explicit dates.
     *
     * Supports range keywords: "7_days_ago", "30_days_ago", "this_week", "this_month", "last_week", "last_month"
     * Also accepts explicit start/end dates.
     *
     * @return array{0: Carbon, 1: Carbon}|null [start, end] tuple
     */
    protected function parseDateRange(?string $from, ?string $to): ?array
    {
        // Handle range keywords in the 'from' field
        if ($from && ! $to) {
            $range = $this->parseRangeKeyword($from);
            if ($range) {
                return $range;
            }
        }

        $start = $from ? $this->parseDate($from) : null;
        $end = $to ? $this->parseDate($to) : null;

        if (! $start && ! $end) {
            return null;
        }

        // Default start to 30 days ago, default end to today
        $start = $start ?? Carbon::today()->subDays(30);
        $end = $end ?? Carbon::today();

        return [$start->startOfDay(), $end->endOfDay()];
    }

    /**
     * Parse relative date keywords like "7_days_ago" or range keywords.
     */
    protected function parseRelativeOrIsoDate(string $input): ?Carbon
    {
        // Match N_days_ago pattern
        if (preg_match('/^(\d+)_days?_ago$/', $input, $matches)) {
            return Carbon::today()->subDays((int) $matches[1]);
        }

        return $this->parseIsoDate($input);
    }

    /**
     * Parse range keywords to [start, end] tuples.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    protected function parseRangeKeyword(string $keyword): ?array
    {
        $keyword = strtolower(trim($keyword));

        return match ($keyword) {
            '7_days_ago', 'last_7_days' => [
                Carbon::today()->subDays(6)->startOfDay(),
                Carbon::today()->endOfDay(),
            ],
            '14_days_ago', 'last_14_days' => [
                Carbon::today()->subDays(13)->startOfDay(),
                Carbon::today()->endOfDay(),
            ],
            '30_days_ago', 'last_30_days' => [
                Carbon::today()->subDays(29)->startOfDay(),
                Carbon::today()->endOfDay(),
            ],
            'this_week' => [
                Carbon::today()->startOfWeek()->startOfDay(),
                Carbon::today()->endOfDay(),
            ],
            'last_week' => [
                Carbon::today()->subWeek()->startOfWeek()->startOfDay(),
                Carbon::today()->subWeek()->endOfWeek()->endOfDay(),
            ],
            'this_month' => [
                Carbon::today()->startOfMonth()->startOfDay(),
                Carbon::today()->endOfDay(),
            ],
            'last_month' => [
                Carbon::today()->subMonth()->startOfMonth()->startOfDay(),
                Carbon::today()->subMonth()->endOfMonth()->endOfDay(),
            ],
            default => null,
        };
    }

    /**
     * Parse ISO date string.
     */
    protected function parseIsoDate(string $input): ?Carbon
    {
        try {
            return Carbon::parse($input)->startOfDay();
        } catch (Exception) {
            return null;
        }
    }
}
