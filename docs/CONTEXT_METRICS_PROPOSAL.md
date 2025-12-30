# Context Metrics Enhancement for Flint

## Problem Statement

Domain agents receive raw events but are expected to identify patterns and trends that require statistical analysis. LLMs are poor at arithmetic and aggregation, leading to:
- Hallucinated statistics
- Inability to detect meaningful deviations
- No baseline comparisons
- Vague insights without numbers

## Solution: Pre-Calculate Metrics

Enhance `AssistantContextService` to include aggregated metrics for numerical domains.

## Proposed Context Structure

```json
{
  "yesterday": {
    "date": "2025-12-29",
    "timezone": "Europe/London",
    "event_count": 45,
    "group_count": 12,
    "service_breakdown": {...},
    "groups": [...],
    "relationships": [...],

    // NEW: Pre-calculated metrics
    "metrics": {
      "sleep": {
        "duration": {
          "current": 7.2,
          "unit": "hours",
          "baseline_7d_avg": 6.8,
          "baseline_30d_avg": 7.5,
          "vs_baseline_30d": -0.3,
          "vs_baseline_30d_pct": -4.0,
          "trend_7d": "stable",
          "min_7d": 6.1,
          "max_7d": 7.8
        },
        "hrv": {
          "current": 65,
          "unit": "ms",
          "baseline_30d_avg": 72,
          "vs_baseline_30d": -7,
          "vs_baseline_30d_pct": -9.7,
          "trend_7d": "declining"
        },
        "resting_hr": {
          "current": 54,
          "unit": "bpm",
          "baseline_30d_avg": 52,
          "vs_baseline_30d": 2,
          "vs_baseline_30d_pct": 3.8,
          "trend_7d": "increasing"
        }
      },

      "activity": {
        "steps": {
          "current": 8234,
          "baseline_7d_avg": 7500,
          "baseline_30d_avg": 8100,
          "vs_baseline_30d_pct": 1.7,
          "trend_7d": "stable"
        },
        "workouts": {
          "count_7d": 4,
          "avg_per_week_30d": 3.5,
          "types_7d": ["strength", "cardio", "strength", "strength"]
        }
      },

      "spending": {
        "total": {
          "today": 45.60,
          "currency": "GBP",
          "week_to_date": 156.20,
          "avg_week_30d": 93.00,
          "vs_avg_week_pct": 67.9
        },
        "by_category": {
          "groceries": {
            "today": 25.30,
            "week_to_date": 67.50,
            "avg_week_30d": 55.00,
            "vs_avg_pct": 22.7
          },
          "dining": {
            "today": 11.80,
            "week_to_date": 52.40,
            "avg_week_30d": 28.00,
            "vs_avg_pct": 87.1
          },
          "transport": {
            "today": 8.50,
            "week_to_date": 36.30,
            "avg_week_30d": 10.00,
            "vs_avg_pct": 263.0
          }
        },
        "unusual_transactions": [
          {
            "amount": 450.00,
            "description": "XYZ Ltd",
            "z_score": 3.2,
            "reason": ">3 std dev from mean transaction"
          }
        ]
      },

      "media": {
        "listening_time": {
          "today_minutes": 120,
          "avg_day_7d": 85,
          "vs_avg_pct": 41.2
        },
        "artists": {
          "unique_7d": 45,
          "new_discoveries_7d": 8,
          "top_genre_7d": "indie"
        }
      },

      "productivity": {
        "tasks": {
          "completed_today": 5,
          "completed_7d": 28,
          "avg_week_30d": 22,
          "vs_avg_pct": 27.3,
          "overdue_count": 3
        }
      },

      "content": {
        "articles_saved": {
          "today": 1,
          "week_7d": 12,
          "avg_week_30d": 8,
          "vs_avg_pct": 50.0
        },
        "top_topics_7d": [
          {"topic": "AI Infrastructure", "count": 5},
          {"topic": "Geopolitics", "count": 3},
          {"topic": "Economics", "count": 2}
        ]
      }
    }
  },

  "today": {
    // Same structure
  }
}
```

## Implementation Details

### 1. Metric Calculation Service

Create `app/Services/MetricsCalculationService.php`:

```php
class MetricsCalculationService
{
    /**
     * Calculate metrics for a timeframe's events
     */
    public function calculateMetrics(
        User $user,
        Collection $events,
        Carbon $date,
        array $domains
    ): array {
        $metrics = [];

        if (in_array('health', $domains)) {
            $metrics['sleep'] = $this->calculateSleepMetrics($user, $events, $date);
            $metrics['activity'] = $this->calculateActivityMetrics($user, $events, $date);
        }

        if (in_array('money', $domains)) {
            $metrics['spending'] = $this->calculateSpendingMetrics($user, $events, $date);
        }

        if (in_array('media', $domains)) {
            $metrics['media'] = $this->calculateMediaMetrics($user, $events, $date);
        }

        if (in_array('online', $domains)) {
            $metrics['productivity'] = $this->calculateProductivityMetrics($user, $events, $date);
        }

        if (in_array('knowledge', $domains)) {
            $metrics['content'] = $this->calculateContentMetrics($user, $events, $date);
        }

        return $metrics;
    }

    protected function calculateSleepMetrics(User $user, Collection $events, Carbon $date): array
    {
        // Get today's sleep event
        $sleepEvent = $events->first(fn($e) =>
            $e->service === 'oura' && $e->action === 'logged_sleep'
        );

        if (!$sleepEvent) {
            return [];
        }

        // Get historical sleep data (7d and 30d)
        $sleep7d = Event::forUser($user->id)
            ->where('service', 'oura')
            ->where('action', 'logged_sleep')
            ->whereBetween('time', [$date->copy()->subDays(7), $date])
            ->get();

        $sleep30d = Event::forUser($user->id)
            ->where('service', 'oura')
            ->where('action', 'logged_sleep')
            ->whereBetween('time', [$date->copy()->subDays(30), $date])
            ->get();

        // Extract duration values
        $current = $sleepEvent->value; // Assuming value is duration in hours
        $baseline7d = $sleep7d->avg('value');
        $baseline30d = $sleep30d->avg('value');

        return [
            'duration' => [
                'current' => round($current, 1),
                'unit' => 'hours',
                'baseline_7d_avg' => round($baseline7d, 1),
                'baseline_30d_avg' => round($baseline30d, 1),
                'vs_baseline_30d' => round($current - $baseline30d, 1),
                'vs_baseline_30d_pct' => round((($current - $baseline30d) / $baseline30d) * 100, 1),
                'trend_7d' => $this->calculateTrend($sleep7d->pluck('value')->toArray()),
                'min_7d' => round($sleep7d->min('value'), 1),
                'max_7d' => round($sleep7d->max('value'), 1),
            ],
            // Similar for HRV, resting_hr, etc.
        ];
    }

    protected function calculateSpendingMetrics(User $user, Collection $events, Carbon $date): array
    {
        // Filter to spending events (had_transaction with negative value)
        $transactions = $events->filter(fn($e) =>
            $e->service === 'monzo' &&
            $e->action === 'had_transaction' &&
            ($e->value ?? 0) < 0
        );

        // Get week-to-date and historical data
        $weekStart = $date->copy()->startOfWeek();
        $wtdTransactions = Event::forUser($user->id)
            ->where('service', 'monzo')
            ->where('action', 'had_transaction')
            ->whereBetween('time', [$weekStart, $date->copy()->endOfDay()])
            ->where('value', '<', 0)
            ->get();

        // Calculate by category
        $byCategory = [];
        $categories = $wtdTransactions->pluck('event_metadata.category')->unique();

        foreach ($categories as $category) {
            if (!$category) continue;

            $categoryTotal = $wtdTransactions
                ->where('event_metadata.category', $category)
                ->sum('value');

            // Get 30d average for this category
            $avg30d = $this->getAverageWeeklySpending($user, $category, $date);

            $byCategory[$category] = [
                'week_to_date' => abs(round($categoryTotal, 2)),
                'avg_week_30d' => round($avg30d, 2),
                'vs_avg_pct' => $avg30d > 0 ? round(((abs($categoryTotal) - $avg30d) / $avg30d) * 100, 1) : 0,
            ];
        }

        return [
            'total' => [
                'week_to_date' => abs(round($wtdTransactions->sum('value'), 2)),
                'currency' => 'GBP',
                // ... more metrics
            ],
            'by_category' => $byCategory,
            'unusual_transactions' => $this->findUnusualTransactions($user, $transactions, $date),
        ];
    }

    protected function calculateTrend(array $values): string
    {
        if (count($values) < 2) {
            return 'stable';
        }

        // Simple linear regression slope
        $n = count($values);
        $x = range(1, $n);
        $y = $values;

        $xy = array_sum(array_map(fn($i) => $x[$i] * $y[$i], range(0, $n-1)));
        $xx = array_sum(array_map(fn($v) => $v * $v, $x));
        $sumX = array_sum($x);
        $sumY = array_sum($y);

        $slope = ($n * $xy - $sumX * $sumY) / ($n * $xx - $sumX * $sumX);

        if (abs($slope) < 0.1) {
            return 'stable';
        }

        return $slope > 0 ? 'increasing' : 'declining';
    }

    protected function findUnusualTransactions(User $user, Collection $transactions, Carbon $date): array
    {
        // Get 90 days of transaction history for statistical baseline
        $historical = Event::forUser($user->id)
            ->where('service', 'monzo')
            ->where('action', 'had_transaction')
            ->whereBetween('time', [$date->copy()->subDays(90), $date])
            ->get();

        $amounts = $historical->pluck('value')->map(fn($v) => abs($v))->toArray();
        $mean = collect($amounts)->avg();
        $stdDev = $this->standardDeviation($amounts);

        $unusual = [];
        foreach ($transactions as $txn) {
            $amount = abs($txn->value);
            $zScore = ($amount - $mean) / $stdDev;

            if ($zScore > 2.5) { // More than 2.5 std dev
                $unusual[] = [
                    'amount' => $amount,
                    'description' => $txn->event_metadata['description'] ?? 'Unknown',
                    'z_score' => round($zScore, 1),
                    'reason' => '>2.5 std dev from mean',
                ];
            }
        }

        return $unusual;
    }
}
```

### 2. Integration with AssistantContextService

Update `generateTimeframeContext()`:

```php
protected function generateTimeframeContext(
    User $user,
    string $timeframe,
    Carbon $baseDate,
    Integration $assistantIntegration,
    ?array $domains = null
): array {
    // ... existing code to get events and groups ...

    // NEW: Calculate metrics
    $metricsService = app(MetricsCalculationService::class);
    $metrics = $metricsService->calculateMetrics($user, $events, $startDate, $domains ?? []);

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
```

### 3. Update Domain Agent Prompts

Update prompts to reference metrics:

```markdown
## Context Data Structure

You receive a JSON object with:
- `groups`: Events grouped by service::action::hour with full details
- `metrics`: Pre-calculated statistics including:
  - Current values vs baselines (7-day, 30-day averages)
  - Trends (increasing, declining, stable)
  - Min/max ranges
  - Percentage changes
  - Unusual patterns flagged

**Use metrics for comparisons and trends. Use groups/events for specific examples and context.**

Example:
- ✅ "Sleep averaged 6.2 hours this week (metrics.sleep.duration.baseline_7d_avg), down 18% from your 30-day baseline (metrics.sleep.duration.vs_baseline_30d_pct)"
- ❌ Trying to calculate these yourself from raw events
```

## Benefits

1. **Accurate Statistics**: No LLM arithmetic errors
2. **Enables Proposal Examples**: All the "X% change from baseline" examples become achievable
3. **Better Insights**: Agents can focus on interpretation, not calculation
4. **Anomaly Detection**: Pre-flagged unusual transactions, HR spikes, etc.
5. **Consistent Baselines**: Same 30-day average used across all insights

## Performance Considerations

- Metrics calculated once per timeframe (9 timeframes × ~50ms = ~450ms overhead)
- Can cache baseline calculations (30d avg rarely changes)
- Only calculate for enabled domains
- Use database indexes on (user_id, service, action, time)

## Testing Strategy

1. Unit test each metric calculation function
2. Integration test context generation with metrics
3. Verify LLM actually uses metrics (not hallucinating numbers)
4. Performance test with large event volumes

## Implementation Priority

**Phase 1.5: Metrics Enhancement** (before quality filters)
- Enables all subsequent phases
- Makes proposal examples realistic
- Required for high-quality insights
