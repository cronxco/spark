<?php

namespace App\Services\Metrics;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Whether a daily value is final. A value is provisional while the day it
 * covers is still running (for metrics that fill in through the day) and for a
 * settle period after it ends (for metrics a source revises).
 *
 * A metric that does not fill in through the day is final once its source
 * has stopped rewriting it for the settle period (measured from `updated_at`).
 *
 * Daily aggregates are stamped at 00:00 UTC on the day they cover. The day is
 * over once it has ended in UTC and in the user's timezone; west of UTC,
 * converting the stamp would land on the previous local day.
 */
class MetricCompleteness
{
    public function __construct(private readonly MetricCatalogue $catalogue) {}

    public function isFinal(string $service, string $action, CarbonInterface $time, string $timezone = 'UTC', ?CarbonInterface $now = null, ?CarbonInterface $updatedAt = null): bool
    {
        return $this->finalAt($service, $action, $time, $timezone, $updatedAt)->lessThanOrEqualTo($now ?? now());
    }

    /**
     * The moment a value becomes final.
     */
    public function finalAt(string $service, string $action, CarbonInterface $time, string $timezone = 'UTC', ?CarbonInterface $updatedAt = null): CarbonInterface
    {
        $settle = $this->catalogue->settleHours($service, $action);

        if (! $this->catalogue->accumulates($service, $action)) {
            $base = Carbon::instance($updatedAt ?? $time)->utc();

            return $updatedAt ? $base->copy()->addHours($settle) : $base->startOfDay();
        }

        $day = Carbon::instance($time)->utc()->toDateString();
        $dayEnd = Carbon::parse($day, 'UTC')->endOfDay()
            ->max(Carbon::parse($day, $timezone)->endOfDay());

        return $dayEnd->copy()->addSeconds(1)->addHours($settle);
    }

    /**
     * The newest day (as a date string) whose values are final.
     */
    public function latestFinalDay(string $service, string $action, string $timezone = 'UTC', ?CarbonInterface $now = null): string
    {
        $now = Carbon::instance($now ?? now());

        for ($back = 0; $back < 4; $back++) {
            $day = $now->copy()->utc()->subDays($back)->startOfDay();

            if ($this->isFinal($service, $action, $day, $timezone, $now)) {
                return $day->toDateString();
            }
        }

        return $now->copy()->utc()->subDays(4)->toDateString();
    }
}
