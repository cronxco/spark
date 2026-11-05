<?php

namespace App\Services;

use App\Integrations\PluginRegistry;
use App\Models\EventObject;
use App\Models\MetricStatistic;

/**
 * Resolves how a metric should be *named and shown*, from the plugin that
 * defines it.
 *
 * Every fact here was already declared in the plugin action-type configs — the
 * human display name, the currency/word formatter, whether Flint should ignore
 * the metric at all. Consumers were instead title-casing the raw event action,
 * which is why a GoCardless balance surfaced as "Had Balance" showing "2082.2"
 * rather than "Balance" showing "£2,082.23": `had_` is the event verb
 * ("Will *had* balance"), not part of the metric's name.
 *
 * MetricStatistic values are already divided by value_multiplier in
 * CalculateMetricStatisticsJob, so they can be handed straight to the
 * plugin's value_formatter.
 */
class MetricPresentation
{
    /**
     * Event action verbs that prefix a metric's action but are not part of its
     * name. Only stripped as a fallback, when the plugin declares no name.
     *
     * @var array<int, string>
     */
    private const ACTION_VERB_PREFIXES = ['had_', 'did_', 'was_', 'got_'];

    /**
     * The name a person would use for this metric.
     */
    public function displayName(MetricStatistic $statistic): string
    {
        $declared = $this->actionType($statistic)['display_name'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        return format_action_title($this->strippedAction($statistic->action));
    }

    /**
     * The plugin's domain — health, money, media, knowledge, online — so a
     * client can file the metric under the right heading instead of assuming
     * every anomaly is a health one.
     */
    public function domain(MetricStatistic $statistic): ?string
    {
        $plugin = PluginRegistry::getPlugin($statistic->service);

        return $plugin ? $plugin::getDomain() : null;
    }

    /**
     * Whether the plugin has asked for this metric to be kept out of Flint.
     * Mirrors DaySummaryService::shouldExcludeAction.
     */
    public function isExcludedFromFlint(MetricStatistic $statistic): bool
    {
        return (bool) ($this->actionType($statistic)['exclude_from_flint'] ?? false);
    }

    /**
     * Render a value through the plugin's own formatter, so currency reads as
     * currency and a banded score reads as its band.
     */
    public function formatValue(MetricStatistic $statistic, ?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Plugin formatters for banded metrics use match(), which compares
        // strictly — match(2.0) never hits a `2 =>` arm and silently falls
        // through to the raw number. Hand whole values over as integers so a
        // band renders as its word.
        $actionType = $this->actionType($statistic);
        $formatted = isset($actionType['value_formatter'])
            ? format_event_value_display(
                $this->normaliseValue($value),
                $statistic->value_unit,
                $statistic->service,
                $statistic->action,
                'action'
            )
            : format_block_value_display($this->normaliseValue($value), $statistic->value_unit);

        // Plugin formatters are Blade templates written for the web and some
        // wrap their units in markup ('78<span class="...">%</span>'). API
        // clients render text, so flatten the tags rather than shipping HTML.
        return trim(preg_replace('/\s+/', ' ', strip_tags($formatted)) ?? '');
    }

    /**
     * Whether the metric is an ordinal band rather than a continuous quantity.
     * A mean and standard deviation over "Limited / Adequate / Solid / Strong /
     * Exceptional" are not meaningful, so a client should show the band and not
     * a percentage change against a fractional baseline.
     */
    public function isOrdinal(MetricStatistic $statistic): bool
    {
        return (bool) ($this->actionType($statistic)['ordinal'] ?? false);
    }

    /**
     * The baseline comparison for one observed value, as a percentage.
     *
     * Returns null for ordinal metrics. A resilience score moving from "Solid"
     * (3) to "Adequate" (2) is one band on a five-point scale, but against a
     * fractional mean of 3.19 it computes as "-37%" — a number that reads like
     * a collapse and has caused exactly that misreading downstream. There is no
     * honest percentage to give here, so give none and let the caller show the
     * band instead.
     */
    public function baselineDeltaPct(MetricStatistic $statistic, ?float $value): ?float
    {
        if ($value === null || ! $statistic->hasValidStatistics() || $this->isOrdinal($statistic)) {
            return null;
        }

        $mean = (float) $statistic->mean_value;

        return $mean != 0.0 ? round((($value - $mean) / abs($mean)) * 100, 1) : 0.0;
    }

    /**
     * Whether an observed value is outside this metric's normal range.
     *
     * Continuous metrics use the stored 2σ bounds. Ordinal metrics cannot:
     * with a mean of 3.19 and a standard deviation of 0.49, the lower bound
     * lands at 2.2, so *every* reading of "Adequate" is flagged as an anomaly
     * even though it is the band immediately below the usual one. For those,
     * ordinary variation is one band either side of the usual band, and only a
     * bigger move is worth raising.
     */
    public function isAnomalous(MetricStatistic $statistic, ?float $value): bool
    {
        if ($value === null || ! $statistic->hasValidStatistics()) {
            return false;
        }

        if ($this->isOrdinal($statistic)) {
            return abs($value - round((float) $statistic->mean_value)) > 1;
        }

        return $value < (float) $statistic->normal_lower_bound
            || $value > (float) $statistic->normal_upper_bound;
    }

    /**
     * Whether a move in `$direction` is good, bad, or neither.
     *
     * Direction is not valence: a balance going up is good news, a
     * cardiovascular age going up is not. Metrics that have not declared
     * `higher_is_better` return 'neutral' — better to say nothing than to tint
     * a windfall as a warning.
     */
    public function valence(MetricStatistic $statistic, string $direction, ?EventObject $account = null): string
    {
        $higherIsBetter = $this->actionType($statistic)['higher_is_better'] ?? null;

        if (is_callable($higherIsBetter)) {
            $higherIsBetter = $account ? $higherIsBetter($account) : null;
        }

        if (! is_bool($higherIsBetter) || ! in_array($direction, ['up', 'down'], true)) {
            return 'neutral';
        }

        $isGood = $direction === 'up' ? $higherIsBetter : ! $higherIsBetter;

        return $isGood ? 'good' : 'bad';
    }

    private function normaliseValue(float $value): int|float
    {
        return floor($value) === $value && abs($value) < PHP_INT_MAX
            ? (int) $value
            : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function actionType(MetricStatistic $statistic): array
    {
        $plugin = PluginRegistry::getPlugin($statistic->service);

        if (! $plugin) {
            return [];
        }

        $actionType = $plugin::getActionTypes()[$statistic->action] ?? null;

        if (! is_array($actionType)) {
            return [];
        }

        $valueUnits = $actionType['value_units']
            ?? (array_key_exists('value_unit', $actionType) ? [$actionType['value_unit']] : null);

        if ($valueUnits !== null && ! in_array($statistic->value_unit, $valueUnits, true)) {
            return [];
        }

        return $actionType;
    }

    private function strippedAction(string $action): string
    {
        foreach (self::ACTION_VERB_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return substr($action, strlen($prefix));
            }
        }

        return $action;
    }
}
