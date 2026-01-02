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

Metrics embedded **within each event** for locality and ease of access:

```json
{
  "yesterday": {
    "date": "2025-12-29",
    "timezone": "Europe/London",
    "event_count": 45,
    "groups": [
      {
        "service": "oura",
        "action": "logged_hrv",
        "hour": "08",
        "count": 1,
        "all_events": [
          {
            "id": "abc-123",
            "time": "2025-12-29T08:15:00Z",
            "service": "oura",
            "action": "logged_hrv",
            "value": 50,
            "unit": "ms",

            // NEW: Metrics embedded in event
            "metrics": {
              "baseline": {
                "mean": 52,
                "min": 23.7,
                "max": 86.9,
                "stddev": 10.72
              },
              "normal_bounds": {
                "lower": 30.6,
                "upper": 73.5
              },
              "vs_baseline": -2,
              "vs_baseline_pct": -3.8,
              "is_anomaly": false,
              "recent_trends": [
                {
                  "type": "trend_down_weekly",
                  "detected_at": "2025-12-28T10:00:00Z",
                  "deviation": -4.2,
                  "significance": 0.78
                }
              ]
            }
          }
        ]
      },
      {
        "service": "monzo",
        "action": "had_transaction",
        "hour": "14",
        "count": 3,
        "all_events": [
          {
            "id": "def-456",
            "time": "2025-12-29T14:32:00Z",
            "service": "monzo",
            "action": "had_transaction",
            "value": -45.60,
            "unit": "GBP",

            // Metrics embedded here too
            "metrics": {
              "baseline": {
                "mean": -35.20,
                "min": -450.00,
                "max": -2.50,
                "stddev": 45.30
              },
              "normal_bounds": {
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
        ]
      }
    ]
  }
}
```

## Implementation

### 1. Embed Metrics in transformEvent() Method

Update `AssistantContextService.php` to enrich each event with metrics:

```php
// In app/Services/AssistantContextService.php

use App\Models\MetricStatistic;
use App\Models\MetricTrend;

protected function generateTimeframeContext(...): array
{
    // ... existing code to get events ...

    // NEW: Pre-load all metric statistics for this timeframe
    $metricsCache = $this->loadMetricsForEvents($user, $events);

    // Pass metrics cache to groupEvents
    $groups = $this->groupEvents($events, $user, $config, $metricsCache);

    return [
        'date' => $startDate->toDateString(),
        'timezone' => $user->timezone ?? 'UTC',
        'event_count' => $events->count(),
        'group_count' => count($groups),
        'service_breakdown' => $serviceBreakdown,
        'groups' => $groups,
        'relationships' => $relationships,
    ];
}

/**
 * Pre-load metric statistics for all events in collection
 */
protected function loadMetricsForEvents(User $user, Collection $events): array
{
    $metricsCache = [];

    // Get unique service::action::unit combinations
    $metricKeys = $events
        ->filter(fn($e) => $e->value !== null && $e->value_unit !== null)
        ->map(fn($e) => [
            'service' => $e->service,
            'action' => $e->action,
            'unit' => $e->value_unit,
        ])
        ->unique(fn($m) => "{$m['service']}.{$m['action']}.{$m['unit']}")
        ->values();

    foreach ($metricKeys as $key) {
        $cacheKey = "{$key['service']}.{$key['action']}.{$key['unit']}";

        // Get the pre-calculated statistic
        $statistic = MetricStatistic::where('user_id', $user->id)
            ->where('service', $key['service'])
            ->where('action', $key['action'])
            ->where('value_unit', $key['unit'])
            ->first();

        if (!$statistic || !$statistic->hasValidStatistics()) {
            continue;
        }

        // Get recent trends/anomalies (last 7 days, unacknowledged)
        $recentTrends = MetricTrend::where('metric_statistic_id', $statistic->id)
            ->where('detected_at', '>=', now()->subDays(7))
            ->unacknowledged()
            ->get();

        $metricsCache[$cacheKey] = [
            'statistic' => $statistic,
            'trends' => $recentTrends,
        ];
    }

    return $metricsCache;
}

/**
 * Group events (updated to pass metrics cache)
 */
protected function groupEvents(Collection $events, User $user, array $config, array $metricsCache): array
{
    // ... existing grouping logic ...

    return [
        // ... existing group data ...
        'all_events' => array_map(
            fn ($e) => $this->transformEvent($e, $config, $metricsCache),
            $group['events']
        ),
    ];
}

/**
 * Transform Event model to clean JSON (updated signature)
 */
protected function transformEvent(Event $event, array $config, array $metricsCache = []): array
{
    $data = [
        'id' => $event->id,
        'time' => $event->time->toISOString(),
        'service' => $event->service,
        'action' => $event->action,
        // ... existing event data ...
    ];

    // NEW: Attach metrics if available for this event
    if ($event->value !== null && $event->value_unit !== null) {
        $metricKey = "{$event->service}.{$event->action}.{$event->value_unit}";

        if (isset($metricsCache[$metricKey])) {
            $data['metrics'] = $this->formatMetricsForEvent(
                $event,
                $metricsCache[$metricKey]['statistic'],
                $metricsCache[$metricKey]['trends']
            );
        }
    }

    return $data;
}

/**
 * Format metrics data for a single event
 */
protected function formatMetricsForEvent(
    Event $event,
    MetricStatistic $statistic,
    Collection $trends
): array {
    $currentValue = $event->formatted_value;
    $baseline = $statistic->mean_value;

    return [
        'baseline' => [
            'mean' => round($statistic->mean_value, 2),
            'min' => round($statistic->min_value, 2),
            'max' => round($statistic->max_value, 2),
            'stddev' => round($statistic->stddev_value, 2),
        ],
        'normal_bounds' => [
            'lower' => round($statistic->normal_lower_bound, 2),
            'upper' => round($statistic->normal_upper_bound, 2),
        ],
        'vs_baseline' => round($currentValue - $baseline, 2),
        'vs_baseline_pct' => $baseline != 0
            ? round((($currentValue - $baseline) / abs($baseline)) * 100, 1)
            : 0,
        'is_anomaly' => $currentValue < $statistic->normal_lower_bound ||
                       $currentValue > $statistic->normal_upper_bound,
        'recent_trends' => $trends->map(fn($t) => [
            'type' => $t->type,
            'detected_at' => $t->detected_at->toISOString(),
            'deviation' => round($t->deviation, 2),
            'significance' => round($t->significance_score, 2),
        ])->toArray(),
    ];
}
```

### 2. Update Domain Agent Prompts

Update all domain agent prompts in `DomainAgentService.php` to reference embedded metrics:

```markdown
## Context Data Structure

You receive events grouped by service::action::hour. Each event includes:

- Standard fields: `id`, `time`, `service`, `action`, `value`, `unit`
- **`metrics` object** (if available): Pre-calculated statistics embedded in the event

**Event with metrics example:**
```json
{
  "id": "abc-123",
  "service": "oura",
  "action": "logged_hrv",
  "value": 50,
  "unit": "ms",
  "metrics": {
    "baseline": {
      "mean": 52,          // Historical average (30+ days)
      "min": 23.7,         // Historical minimum
      "max": 86.9,         // Historical maximum
      "stddev": 10.72      // Standard deviation
    },
    "normal_bounds": {
      "lower": 30.6,       // Mean - 2σ
      "upper": 73.5        // Mean + 2σ
    },
    "vs_baseline": -2,     // Difference from mean
    "vs_baseline_pct": -3.8,  // Percentage change
    "is_anomaly": false,   // True if outside normal_bounds
    "recent_trends": [
      {
        "type": "trend_down_weekly",
        "detected_at": "2025-12-28T10:00:00Z",
        "deviation": -4.2,
        "significance": 0.78
      }
    ]
  }
}
```

**Using metrics in insights:**
- ✅ Access directly from event: `event.metrics.vs_baseline_pct`
- ✅ "HRV was {event.value} ms, down {event.metrics.vs_baseline_pct}% from your {event.metrics.baseline.mean} ms baseline"
- ❌ Don't calculate statistics yourself - use the pre-calculated metrics

**Anomaly detection:**
- If `event.metrics.is_anomaly` is `true`, value is >2σ from baseline → flag this!
- Check `event.metrics.recent_trends` for detected patterns

**When metrics are missing:**
- Not all events have metrics (need 30+ days data, 10+ events)
- `metrics` field won't exist on newer integrations
- Fall back to raw event values without baseline comparisons
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
