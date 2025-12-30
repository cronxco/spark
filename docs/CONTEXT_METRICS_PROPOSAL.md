# Context Metrics Enhancement for Flint

## Problem Statement

Domain agents receive raw events but are expected to identify patterns and trends that require statistical analysis. LLMs are poor at arithmetic and aggregation, leading to:
- Hallucinated statistics
- Inability to detect meaningful deviations
- No baseline comparisons
- Vague insights without numbers

## Discovery: Metrics Already Exist!

Spark **already calculates** comprehensive metrics via:
- `MetricStatistic` model: min, max, mean, stddev, normal bounds (mean ± 2σ)
- `MetricTrend` model: weekly/monthly/quarterly trends, anomaly detection
- Background jobs (`CalculateMetricStatisticsJob`, `DetectMetricTrendsJob`) that keep these updated
- At least 30 days of historical data required for statistics
- At least 10 events required per metric

**The problem:** These metrics aren't included in the AssistantContext JSON sent to Flint agents.

## Solution: Include Existing Metrics in Context

Much simpler than originally proposed - just query and include existing metrics!

## Proposed Context Structure

```json
{
  "yesterday": {
    "date": "2025-12-29",
    "timezone": "Europe/London",
    "event_count": 45,
    "groups": [...],
    "relationships": [...],

    // NEW: Metrics from MetricStatistic/MetricTrend tables
    "metrics": {
      "oura.logged_sleep": {
        "unit": "hours",
        "current": 7.2,
        "baseline": {
          "mean": 7.5,
          "min": 6.1,
          "max": 8.3,
          "stddev": 0.6
        },
        "normal_range": {
          "lower": 6.3,
          "upper": 8.7
        },
        "vs_baseline": -0.3,
        "vs_baseline_pct": -4.0,
        "is_anomaly": false,
        "recent_trends": [
          {
            "type": "trend_down_weekly",
            "detected_at": "2025-12-28T10:00:00Z",
            "deviation": -0.4,
            "significance": 0.85
          }
        ]
      },
      "oura.logged_hrv": {
        "unit": "ms",
        "current": 65,
        "baseline": {
          "mean": 72,
          "min": 55,
          "max": 95,
          "stddev": 8.5
        },
        "normal_range": {
          "lower": 55.0,
          "upper": 89.0
        },
        "vs_baseline": -7,
        "vs_baseline_pct": -9.7,
        "is_anomaly": false,
        "recent_trends": []
      },
      "monzo.had_transaction": {
        "unit": "GBP",
        "current": -45.60,
        "baseline": {
          "mean": -35.20,
          "min": -450.00,
          "max": -2.50,
          "stddev": 45.30
        },
        "normal_range": {
          "lower": -125.80,
          "upper": 55.40
        },
        "vs_baseline": -10.40,
        "vs_baseline_pct": 29.5,
        "is_anomaly": false,
        "recent_trends": [
          {
            "type": "anomaly_high",
            "detected_at": "2025-12-27T15:30:00Z",
            "deviation": 414.80,
            "significance": 0.95
          }
        ]
      }
    }
  }
}
```

## Implementation

### 1. Add Metrics Query Method to AssistantContextService

```php
// In app/Services/AssistantContextService.php

use App\Models\MetricStatistic;
use App\Models\MetricTrend;

protected function generateTimeframeContext(...): array
{
    // ... existing code to get events and groups ...

    // NEW: Include metrics from existing MetricStatistic records
    $metrics = $this->getMetricsForTimeframe($user, $events, $domains);

    return [
        'date' => $startDate->toDateString(),
        'timezone' => $user->timezone ?? 'UTC',
        'event_count' => $events->count(),
        'group_count' => count($groups),
        'service_breakdown' => $serviceBreakdown,
        'groups' => $groups,
        'relationships' => $relationships,
        'metrics' => $metrics, // NEW
    ];
}

protected function getMetricsForTimeframe(
    User $user,
    Collection $events,
    ?array $domains
): array {
    $metrics = [];

    // Get unique service::action::unit combinations from events in this timeframe
    $metricKeys = $events
        ->filter(fn($e) => $e->value !== null && $e->value_unit !== null)
        ->map(fn($e) => [
            'service' => $e->service,
            'action' => $e->action,
            'unit' => $e->value_unit,
            'domain' => $e->domain,
        ])
        ->unique(fn($m) => "{$m['service']}.{$m['action']}.{$m['unit']}")
        ->filter(fn($m) => !$domains || in_array($m['domain'], $domains));

    foreach ($metricKeys as $key) {
        // Get the pre-calculated statistic
        $statistic = MetricStatistic::where('user_id', $user->id)
            ->where('service', $key['service'])
            ->where('action', $key['action'])
            ->where('value_unit', $key['unit'])
            ->first();

        if (!$statistic || !$statistic->hasValidStatistics()) {
            continue;
        }

        // Get recent trends/anomalies for this metric (last 7 days, unacknowledged)
        $recentTrends = MetricTrend::where('metric_statistic_id', $statistic->id)
            ->where('detected_at', '>=', now()->subDays(7))
            ->unacknowledged()
            ->get();

        // Get current value from events in this timeframe
        $currentEvents = $events->filter(fn($e) =>
            $e->service === $key['service'] &&
            $e->action === $key['action'] &&
            $e->value_unit === $key['unit']
        );

        if ($currentEvents->isEmpty()) {
            continue;
        }

        $currentValue = $currentEvents->avg('formatted_value');

        // Build metric entry
        $metricKey = "{$key['service']}.{$key['action']}";
        $metrics[$metricKey] = [
            'unit' => $key['unit'],
            'current' => round($currentValue, 2),
            'baseline' => [
                'mean' => round($statistic->mean_value, 2),
                'min' => round($statistic->min_value, 2),
                'max' => round($statistic->max_value, 2),
                'stddev' => round($statistic->stddev_value, 2),
            ],
            'normal_range' => [
                'lower' => round($statistic->normal_lower_bound, 2),
                'upper' => round($statistic->normal_upper_bound, 2),
            ],
            'vs_baseline' => round($currentValue - $statistic->mean_value, 2),
            'vs_baseline_pct' => $statistic->mean_value > 0
                ? round((($currentValue - $statistic->mean_value) / $statistic->mean_value) * 100, 1)
                : 0,
            'is_anomaly' => $currentValue < $statistic->normal_lower_bound ||
                           $currentValue > $statistic->normal_upper_bound,
            'recent_trends' => $recentTrends->map(fn($t) => [
                'type' => $t->type,
                'detected_at' => $t->detected_at->toISOString(),
                'deviation' => round($t->deviation, 2),
                'significance' => round($t->significance_score, 2),
            ])->toArray(),
        ];
    }

    return $metrics;
}
```

### 2. Update Domain Agent Prompts

Update all domain agent prompts in `DomainAgentService.php` to reference metrics:

```markdown
## Context Data Structure

You receive a JSON object with:

- `groups`: Events grouped by service::action::hour with full details
- `metrics`: Pre-calculated statistics from MetricStatistic/MetricTrend tables

**Metrics object structure:**
```json
"metrics": {
  "service.action": {
    "unit": "hours|bpm|GBP|etc",
    "current": 7.2,              // Current value in this timeframe
    "baseline": {
      "mean": 7.5,               // Historical average (30+ days)
      "min": 6.1,                // Historical minimum
      "max": 8.3,                // Historical maximum
      "stddev": 0.6              // Standard deviation
    },
    "normal_range": {
      "lower": 6.3,              // Mean - 2σ
      "upper": 8.7               // Mean + 2σ
    },
    "vs_baseline": -0.3,         // Difference from mean
    "vs_baseline_pct": -4.0,     // Percentage change from mean
    "is_anomaly": false,         // True if outside normal_range
    "recent_trends": [...]       // Detected trends in last 7 days
  }
}
```

**Use metrics for all comparisons and trends:**
- ✅ "Sleep averaged {metrics.oura.logged_sleep.current} hours, down {metrics.oura.logged_sleep.vs_baseline_pct}% from your baseline of {metrics.oura.logged_sleep.baseline.mean} hours"
- ❌ Trying to calculate these yourself from raw events

**Anomaly detection:**
- If `is_anomaly: true`, the value is >2σ from historical mean - flag this!
- Check `recent_trends` array for detected patterns (trend_up_weekly, anomaly_high, etc.)

**When metrics are unavailable:**
- Not all actions have metrics (need 30+ days of data, 10+ events)
- Fall back to raw event data for newer services/actions
```

### 3. Benefits

1. **Accurate Statistics**: Uses pre-calculated mean/stddev from database
2. **Anomaly Detection**: Automatically flags values outside normal range
3. **Trend Detection**: Recent trends already detected and included
4. **No LLM Math**: All calculations done in PHP with proper precision
5. **Consistent Baselines**: Same historical baseline used across all insights
6. **Already Maintained**: Background jobs keep metrics up-to-date

### 4. Performance Considerations

- Minimal overhead: ~1 query per unique metric in timeframe
- Metrics already indexed by (user_id, service, action, value_unit)
- Trends query limited to last 7 days + unacknowledged only
- Can cache metric statistics (updated hourly at most)

### 5. Testing Strategy

1. Unit test `getMetricsForTimeframe()` with various scenarios
2. Integration test context generation includes metrics
3. Verify domain agents actually use metrics (not hallucinating numbers)
4. Test with users who have insufficient data (< 30 days)
5. Test edge cases (zero baseline, negative values, etc.)

### 6. Fallback Behavior

- If MetricStatistic doesn't exist for a metric → skip it from context
- If insufficient data (< 30 days, < 10 events) → skip it
- Agents can still analyze raw events, just without baseline comparisons
- Gracefully degrades for new users or new integrations

## Implementation Priority

**Phase 0: Add Existing Metrics to Context**
1. Add `getMetricsForTimeframe()` method to `AssistantContextService`
2. Update `generateTimeframeContext()` to include metrics
3. Add unit tests
4. Update domain agent prompts to reference metrics
5. Test with real user data

Much simpler than creating a new metrics system - just expose what already exists!
