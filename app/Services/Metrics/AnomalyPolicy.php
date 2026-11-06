<?php

namespace App\Services\Metrics;

use App\Models\MetricStatistic;

/**
 * The one rule for whether a value is worth raising, shared by detection, the
 * Up to Speed feed, the day summary and the MCP tools.
 *
 * Adverse only: with a known polarity a move in the good direction is never an
 * anomaly. With an unknown polarity both directions are raised.
 */
class AnomalyPolicy
{
    /**
     * Distance from the centre, in spreads, outside which a value is raised.
     */
    public const RAISE_SIGMA = 2.0;

    /**
     * Distance a single day needs before it is shown; a run of days shows at
     * the raise distance.
     */
    public const SURFACE_SIGMA = 3.0;

    /**
     * A streak at least this long is the new normal, not news.
     */
    public const STREAK_CAP = 7;

    public function __construct(private readonly MetricCatalogue $catalogue) {}

    /**
     * Judge one final value against a statistic.
     *
     * @return array{direction: string, deviation: float, adverse: bool, type: string}|null
     */
    public function judge(MetricStatistic $statistic, ?float $value): ?array
    {
        if ($value === null || ! $statistic->hasValidStatistics()) {
            return null;
        }

        $service = $statistic->service;
        $action = $statistic->action;

        if (! $this->catalogue->raisesAnomalies($service, $action)) {
            return null;
        }

        $centre = (float) $statistic->mean_value;
        $spread = (float) $statistic->stddev_value;

        if ($this->catalogue->method($service, $action) === MetricCatalogue::METHOD_BAND) {
            $steps = abs($value - round($centre));

            if ($steps <= 1) {
                return null;
            }

            $direction = $value > $centre ? 'up' : 'down';
            $deviation = $steps;
        } else {
            // No spread means nothing to measure against: every value is the same.
            if ($spread <= 0.0) {
                return null;
            }

            $high = $value > (float) $statistic->normal_upper_bound;
            $low = $value < (float) $statistic->normal_lower_bound;

            if (! $high && ! $low) {
                return null;
            }

            $direction = $high ? 'up' : 'down';
            $deviation = abs($value - $centre) / $spread;
        }

        $higherIsBetter = $this->catalogue->higherIsBetter($service, $action);
        $adverse = $higherIsBetter === null
            ? true
            : ($direction === 'up') !== $higherIsBetter;

        if (! $adverse) {
            return null;
        }

        return [
            'direction' => $direction,
            'deviation' => $deviation,
            'adverse' => true,
            'type' => $direction === 'up' ? 'anomaly_high' : 'anomaly_low',
        ];
    }

    /**
     * Whether an anomaly that has already been raised is too weak or too
     * stale to show: a single day needs the surface distance, and a long
     * streak is the new normal.
     */
    public function isNoise(float $deviation, int $streakDays): bool
    {
        if ($streakDays >= self::STREAK_CAP) {
            return true;
        }

        return $streakDays <= 1 && $deviation < self::SURFACE_SIGMA;
    }
}
