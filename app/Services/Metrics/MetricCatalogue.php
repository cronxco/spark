<?php

namespace App\Services\Metrics;

use App\Integrations\PluginRegistry;

/**
 * Which metrics are judged, and how. Reads config/metrics.php; a metric that
 * is not listed is never judged, only charted.
 */
class MetricCatalogue
{
    public const KIND_READING = 'reading';

    public const KIND_DAILY_TOTAL = 'daily_total';

    public const KIND_ORDINAL = 'ordinal';

    public const METHOD_MEAN_SD = 'mean_sd';

    public const METHOD_MEDIAN_MAD = 'median_mad';

    public const METHOD_BAND = 'band';

    /**
     * Kinds that can be baselined and judged at all.
     *
     * @var array<int, string>
     */
    private const JUDGEABLE_KINDS = [self::KIND_READING, self::KIND_DAILY_TOTAL, self::KIND_ORDINAL];

    /**
     * The catalogue entry for a metric, or null when it is not listed.
     *
     * @return array<string, mixed>|null
     */
    public function entry(string $service, string $action): ?array
    {
        $metrics = config('metrics.metrics', []);

        return $metrics[$this->canonicalService($service) . ".{$action}"]
            ?? $metrics["{$service}.{$action}"]
            ?? null;
    }

    public function kind(string $service, string $action): ?string
    {
        return $this->entry($service, $action)['kind'] ?? null;
    }

    public function isJudgeable(string $service, string $action): bool
    {
        return in_array($this->kind($service, $action), self::JUDGEABLE_KINDS, true);
    }

    /**
     * Whether adverse anomalies are raised for this metric.
     */
    public function raisesAnomalies(string $service, string $action): bool
    {
        return $this->isJudgeable($service, $action)
            && ($this->entry($service, $action)['anomalies'] ?? false) === true
            && $this->duplicateOf($service, $action) === null;
    }

    /**
     * Whether period and typical-day insights are produced for this metric.
     */
    public function producesInsights(string $service, string $action): bool
    {
        return $this->isJudgeable($service, $action)
            && ($this->entry($service, $action)['insights'] ?? false) === true
            && $this->duplicateOf($service, $action) === null;
    }

    public function method(string $service, string $action): string
    {
        $entry = $this->entry($service, $action) ?? [];

        if (isset($entry['method'])) {
            return $entry['method'];
        }

        return match ($entry['kind'] ?? null) {
            self::KIND_DAILY_TOTAL => self::METHOD_MEDIAN_MAD,
            self::KIND_ORDINAL => self::METHOD_BAND,
            default => self::METHOD_MEAN_SD,
        };
    }

    /**
     * Polarity: the catalogue first, then the plugin's own declaration.
     * Null means unknown, and an unknown polarity judges both directions.
     */
    public function higherIsBetter(string $service, string $action): ?bool
    {
        $declared = $this->entry($service, $action)['higher_is_better'] ?? null;

        if (is_bool($declared)) {
            return $declared;
        }

        $pluginValue = $this->actionType($service, $action)['higher_is_better'] ?? null;

        return is_bool($pluginValue) ? $pluginValue : null;
    }

    public function accumulates(string $service, string $action): bool
    {
        $entry = $this->entry($service, $action) ?? [];

        return (bool) ($entry['accumulates'] ?? $this->actionType($service, $action)['accumulates_through_day'] ?? false);
    }

    public function settleHours(string $service, string $action): int
    {
        return (int) ($this->entry($service, $action)['settle_hours'] ?? 0);
    }

    /**
     * The preferred source (`service.action`) for the same concept, if this
     * metric is a duplicate of one.
     */
    public function duplicateOf(string $service, string $action): ?string
    {
        return $this->entry($service, $action)['duplicate_of'] ?? null;
    }

    /**
     * The spelling of a service to keep, for services recorded two ways.
     */
    public function canonicalService(string $service): string
    {
        foreach (config('metrics.service_aliases', []) as $canonical => $aliases) {
            if ($service === $canonical || in_array($service, $aliases, true)) {
                return $canonical;
            }
        }

        return $service;
    }

    /**
     * @return array<int, string>
     */
    public function aliasesOf(string $canonical): array
    {
        return config('metrics.service_aliases', [])[$canonical] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionType(string $service, string $action): array
    {
        $plugin = PluginRegistry::getPlugin($service);
        $config = $plugin ? ($plugin::getActionTypes()[$action] ?? []) : [];

        return is_array($config) ? $config : [];
    }
}
