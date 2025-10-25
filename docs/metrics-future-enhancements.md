# Metrics Feature - Future Enhancements

This document outlines potential future enhancements to the metrics, statistics, and trends feature.

## 1. Notifications

### Overview

Implement push and email notifications to alert users when new significant trends or anomalies are detected.

### Implementation Plan

#### 1.1 Create Notification Class

Create `App\Notifications\MetricTrendDetected` notification:

```php
<?php

namespace App\Notifications;

use App\Models\MetricTrend;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MetricTrendDetected extends Notification
{
    use Queueable;

    protected MetricTrend $trend;

    public function __construct(MetricTrend $trend)
    {
        $this->trend = $trend;
    }

    public function via($notifiable): array
    {
        $channels = [];

        if ($notifiable->hasEmailNotificationsEnabled('metric_trends')) {
            $channels[] = 'mail';
        }

        // Future: add push notifications
        // if ($notifiable->hasPushNotificationsEnabled('metric_trends')) {
        //     $channels[] = 'push';
        // }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $metric = $this->trend->metricStatistic;

        return (new MailMessage)
            ->subject("Metric Alert: {$metric->getDisplayName()}")
            ->line("We've detected a {$this->trend->getTypeLabel()} for {$metric->getDisplayName()}.")
            ->line("Current value: {$this->trend->current_value} {$metric->value_unit}")
            ->line("Baseline: {$this->trend->baseline_value} {$metric->value_unit}")
            ->action('View Details', url('/metrics/' . $metric->id))
            ->line('This trend will remain visible until you acknowledge it.');
    }
}
```

#### 1.2 Dispatch Notifications

Modify `DetectMetricAnomaliesJob` and `DetectMetricTrendsJob` to dispatch notifications:

```php
// After creating a new MetricTrend
$user = $metricStatistic->user;

// Check user notification preferences
if (!$user->isMetricTrackingDisabled($metric->service, $metric->action, $metric->value_unit)) {
    $user->notify(new MetricTrendDetected($trend));
}
```

#### 1.3 Respect User Preferences

- Check `hasEmailNotificationsEnabled('metric_trends')` before sending
- Respect work hours settings (`isInWorkHours()`)
- Support delayed sending mode for digest notifications

#### 1.4 Add Settings UI

Create user settings page to:

- Enable/disable metric trend notifications
- Choose notification channels (email, push)
- Set minimum significance threshold for notifications
- Configure which types of trends trigger notifications (anomalies only, all trends, etc.)

### Considerations

- Avoid notification fatigue: only notify for significant trends
- Provide easy way to unsubscribe from specific metrics
- Group multiple trends in digest format if many detected simultaneously
- Include chart/graph in email showing the trend visually

---

## 2. Summary Emails

### Overview

Weekly or monthly digest emails summarizing all detected trends and anomalies.

### Implementation Plan

#### 2.1 Create Summary Job

Create `App\Jobs\Metrics\SendMetricsSummaryEmail`:

```php
<?php

namespace App\Jobs\Metrics;

use App\Models\MetricTrend;
use App\Models\User;
use App\Notifications\MetricsSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMetricsSummaryEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $period; // 'weekly' or 'monthly'

    public function __construct(string $period = 'weekly')
    {
        $this->period = $period;
    }

    public function handle(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            // Get unacknowledged trends from the period
            $startDate = $this->period === 'weekly'
                ? now()->subWeek()
                : now()->subMonth();

            $trends = MetricTrend::whereHas('metricStatistic', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->unacknowledged()
            ->where('detected_at', '>=', $startDate)
            ->get();

            if ($trends->isEmpty()) {
                continue;
            }

            // Send summary
            $user->notify(new MetricsSummary($trends, $this->period));
        }
    }
}
```

#### 2.2 Schedule Summary Emails

In `app/Console/Kernel.php`:

```php
// Send weekly summary every Monday at 9am
$schedule
    ->job(new \App\Jobs\Metrics\SendMetricsSummaryEmail('weekly'))
    ->weekly()
    ->mondays()
    ->at('09:00');

// Send monthly summary on the 1st at 9am
$schedule
    ->job(new \App\Jobs\Metrics\SendMetricsSummaryEmail('monthly'))
    ->monthly()
    ->at('09:00');
```

#### 2.3 Summary Email Template

Create engaging email template with:

- Header with period covered (e.g., "Your Weekly Metrics Summary")
- Grouped trends by metric type
- Visual indicators (↑↓ arrows, color coding)
- Quick stats (e.g., "5 metrics trending up, 2 trending down")
- Call-to-action buttons to view full dashboard
- Option to unsubscribe from summaries

### Considerations

- Allow users to choose summary frequency (daily, weekly, monthly, never)
- Include only metrics user cares about (not disabled ones)
- Prioritize most significant trends at top
- Include sparkline charts for visual context
- Provide "acknowledge all" link to clear unacknowledged trends

---

## 3. Cards Integration

### Overview

Display metric trends as cards in the main feed once the card system is implemented.

### Implementation Plan

#### 3.1 Card Type Definition

Create new card type `metric_trend` in the cards system.

#### 3.2 Card Generation

When a significant trend is detected, create a card:

```php
// In DetectMetricTrendsJob or DetectMetricAnomaliesJob
use App\Models\Card;

$card = Card::create([
    'user_id' => $user->id,
    'type' => 'metric_trend',
    'title' => "Your {$metric->getDisplayName()} is {$trend->getTypeLabel()}",
    'content' => "Current: {$trend->current_value} {$metric->value_unit}, " .
                 "Baseline: {$trend->baseline_value} {$metric->value_unit}",
    'metadata' => [
        'metric_trend_id' => $trend->id,
        'metric_statistic_id' => $metric->id,
        'direction' => $trend->getDirection(),
        'significance_score' => $trend->significance_score,
    ],
    'priority' => $this->calculateCardPriority($trend),
    'expires_at' => now()->addWeeks(2), // Auto-expire after 2 weeks
]);
```

#### 3.3 Card Display Component

Create Livewire component for metric trend cards:

- Show metric name and value
- Display trend direction with visual indicator (arrow)
- Include mini sparkline chart
- "View Details" button linking to metric detail page
- "Acknowledge" button to dismiss

#### 3.4 Auto-Acknowledgement

When user views a metric trend card:

- Automatically acknowledge the corresponding MetricTrend
- Mark card as "viewed"
- Optionally hide card after acknowledgement

#### 3.5 Trend "Leveling Up"

Implement logic for trend progression:

```php
/**
 * Check if trend should be "leveled up" from weekly to monthly
 */
protected function shouldLevelUp(MetricTrend $weeklyTrend): bool
{
    // If weekly trend persists for 4 weeks, create monthly trend
    $weeksSinceDetection = $weeklyTrend->detected_at->diffInWeeks(now());

    if ($weeksSinceDetection >= 4) {
        // Check if monthly trend exists
        $monthlyTrend = MetricTrend::where('metric_statistic_id', $weeklyTrend->metric_statistic_id)
            ->where('type', str_replace('weekly', 'monthly', $weeklyTrend->type))
            ->unacknowledged()
            ->exists();

        return !$monthlyTrend;
    }

    return false;
}
```

When leveling up:

- Acknowledge the lower-level trend (weekly → monthly)
- Create new higher-level trend
- Generate new card for the elevated trend
- Notify user of the sustained trend

#### 3.6 Card Priorities

Set card priority based on:

- Trend significance score
- Trend type (anomalies higher than trends)
- Direction (user-configurable: e.g., upward sleep quality = high priority)
- Recency of detection
- User's engagement with similar metrics

### Considerations

- Don't create duplicate cards for same trend
- Archive cards when trend is acknowledged
- Allow user to "snooze" trend cards
- Provide context: "This is 2.5 standard deviations above your normal"
- Link to historical data showing when trend started

---

## 4. Additional Future Enhancements

### 4.1 Predictive Analytics

- Forecast future values based on detected trends
- Alert when metric is projected to reach concerning threshold
- Suggest interventions based on correlations with other metrics

### 4.2 Metric Correlations

- Detect correlations between different metrics
- Example: "Your sleep quality tends to increase when your step count is above 8000"
- Create correlation cards showing related metrics

### 4.3 Goal Setting

- Allow users to set targets for metrics
- Track progress toward goals
- Celebrate when goals are achieved
- Alert when trending away from goals

### 4.4 Comparative Analytics

- Compare user's metrics to aggregate anonymized data (if user opts in)
- "Your readiness score is 15% higher than average for your age group"
- Provide context without compromising privacy

### 4.5 Custom Thresholds

- Allow users to set custom anomaly thresholds per metric
- Override default 2 standard deviations with user preference
- Set absolute value thresholds (e.g., alert if heart rate > 100bpm)

### 4.6 Trend Explanations

- Use AI/LLM to generate natural language explanations of trends
- "Your activity score has been trending down this week, which may be related to..."
- Provide actionable insights and suggestions

### 4.7 Data Export

- Export metric statistics and trends to CSV/JSON
- Generate PDF reports with charts and summaries
- Integration with third-party analytics tools

---

## Implementation Priority

Recommended implementation order:

1. **Notifications** (highest impact, builds on existing infrastructure)
2. **Cards Integration** (once card system exists, high visibility)
3. **Summary Emails** (enhances user engagement)
4. **Custom Thresholds** (user control, relatively simple)
5. **Goal Setting** (motivational feature)
6. **Metric Correlations** (complex but valuable)
7. **Predictive Analytics** (most complex, requires ML)

---

## Technical Debt & Maintenance

### Monitoring

- Set up alerts for job failures (already handled by Sentry integration)
- Monitor queue depth for metric calculation jobs
- Track percentage of metrics with valid statistics

### Performance Optimization

- Consider caching frequently accessed metric statistics
- Implement Redis for real-time anomaly detection if event volume increases
- Add database query optimization as data grows
- Consider archiving old trends (>6 months acknowledged)

### Testing

- Add comprehensive test coverage for all jobs
- Create factories for MetricStatistic and MetricTrend
- Test edge cases (empty datasets, insufficient data, etc.)
- Performance testing with large datasets

### Documentation

- Add inline comments for complex statistical calculations
- Document threshold values and their rationale
- Create user-facing documentation explaining how metrics work
- Add API documentation if metrics are exposed via API
