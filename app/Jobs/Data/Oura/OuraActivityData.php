<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Oura\Traits\HasOuraBlocks;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Facades\Log;

class OuraActivityData extends BaseProcessingJob
{
    use HasOuraBlocks;

    public function getIntegration()
    {
        return $this->integration;
    }

    public function getRawData()
    {
        return $this->rawData;
    }

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function process(): void
    {
        $activityItems = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($activityItems)) {
            return;
        }

        Log::info('OuraActivityData: Processing activity data', [
            'integration_id' => $this->integration->id,
            'activity_count' => count($activityItems),
        ]);

        // Batch check existence to prevent N+1 queries
        $sourceIds = collect($activityItems)
            ->map(fn ($item) => $item['day'] ? "oura_activity_{$this->integration->id}_{$item['day']}" : null)
            ->filter()
            ->toArray();

        $existingSourceIds = Event::where('integration_id', $this->integration->id)
            ->whereIn('source_id', $sourceIds)
            ->pluck('source_id')
            ->flip(); // For O(1) lookup

        foreach ($activityItems as $item) {
            $this->createEnhancedActivityEvent($plugin, $item, $existingSourceIds);
        }

        Log::info('OuraActivityData: Completed processing activity data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    /**
     * Create activity event with full API v2 field support
     */
    private function createEnhancedActivityEvent(OuraPlugin $plugin, array $item, \Illuminate\Support\Collection $existingSourceIds): void
    {
        $day = $item['day'] ?? null;
        if (! $day) {
            return;
        }

        $sourceId = "oura_activity_{$this->integration->id}_{$day}";

        // Check existence in memory (no query)
        if ($existingSourceIds->has($sourceId)) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);
        $target = $plugin->getStaticMetricObject(
            $this->integration,
            'daily_activity',
            'Daily Activity',
            'Daily activity summary with comprehensive metrics'
        );

        $score = $item['score'] ?? null;
        [$encodedScore, $scoreMultiplier] = $plugin->encodeNumericValue($score);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_activity_score',
            'value' => $encodedScore,
            'value_multiplier' => $scoreMultiplier,
            'value_unit' => 'percent',
            'event_metadata' => [
                'day' => $day,
                'steps' => $item['steps'] ?? 0,
                'calories_total' => $item['cal_total'] ?? 0,
            ],
            'target_id' => $target->id,
        ]);

        // Add score contributors as blocks
        $contributors = $item['contributors'] ?? [];
        if (! empty($contributors)) {
            $this->createContributorBlocks($event, $contributors, $plugin);
        }

        // Add core activity metrics
        $coreMetrics = $this->getStandardActivityMetrics();
        $coreMetrics = array_merge($coreMetrics, [
            'total_calories' => ['unit' => 'kcal', 'title' => 'Total Calories (Enhanced)', 'type' => 'core_metric', 'category' => 'energy'],
            'target_meters' => ['unit' => 'meters', 'title' => 'Target Distance', 'type' => 'target_metric', 'category' => 'goal'],
            'meters_to_target' => ['unit' => 'meters', 'title' => 'Meters To Target', 'type' => 'target_metric', 'category' => 'goal'],
        ]);

        $this->createActivityMetricBlocks($event, $item, $coreMetrics, $plugin);

        // Add MET-based metrics
        $metMetrics = $this->getMetActivityMetrics();
        $this->createActivityMetricBlocks($event, $item, $metMetrics, $plugin);

        // Add time-based activity breakdown
        $timeMetrics = [
            'high_activity_time' => ['unit' => 'seconds', 'title' => 'High Activity Time', 'type' => 'time_metric', 'category' => 'activity_breakdown'],
            'low_activity_time' => ['unit' => 'seconds', 'title' => 'Low Activity Time', 'type' => 'time_metric', 'category' => 'activity_breakdown'],
            'medium_activity_time' => ['unit' => 'seconds', 'title' => 'Medium Activity Time', 'type' => 'time_metric', 'category' => 'activity_breakdown'],
            'sedentary_time' => ['unit' => 'seconds', 'title' => 'Sedentary Time', 'type' => 'time_metric', 'category' => 'activity_breakdown'],
            'resting_time' => ['unit' => 'seconds', 'title' => 'Resting Time', 'type' => 'time_metric', 'category' => 'activity_breakdown'],
        ];

        $this->createActivityMetricBlocks($event, $item, $timeMetrics, $plugin);

        // Add inactivity alerts count if present
        $inactivityAlerts = $item['inactivity_alerts'] ?? null;
        if ($inactivityAlerts !== null) {
            [$encodedAlerts, $alertsMultiplier] = $plugin->encodeNumericValue($inactivityAlerts);
            $event->createBlock([
                'block_type' => 'activity_metric',
                'time' => $event->time,
                'integration_id' => $this->integration->id,
                'title' => 'Inactivity Alerts',
                'metadata' => ['type' => 'alert_count'],
                'value' => $encodedAlerts,
                'value_multiplier' => $alertsMultiplier,
                'value_unit' => 'count',
            ]);
        }

        // Add 5-minute activity classification data if present
        $class5Min = $item['class_5_min'] ?? null;
        if ($class5Min && is_array($class5Min)) {
            $event->createBlock([
                'block_type' => 'activity_metric',
                'time' => $event->time,
                'integration_id' => $this->integration->id,
                'title' => '5-Minute Activity Classification',
                'metadata' => [
                    'type' => 'classification_series',
                    'data_points' => count($class5Min),
                    'class_5_min' => $class5Min,
                ],
                'content' => count($class5Min) . ' data points',
                'value' => count($class5Min),
                'value_multiplier' => 1,
                'value_unit' => 'data_points',
            ]);
        }

        // Add MET time series data if present
        $met = $item['met'] ?? null;
        if ($met && is_array($met)) {
            $event->createBlock([
                'block_type' => 'activity_metric',
                'time' => $event->time,
                'integration_id' => $this->integration->id,
                'title' => 'MET Time Series',
                'metadata' => [
                    'type' => 'met_series',
                    'data_points' => count($met),
                    'met_data' => $met,
                ],
                'content' => count($met) . ' MET measurements',
                'value' => count($met),
                'value_multiplier' => 1,
                'value_unit' => 'data_points',
            ]);
        }
    }
}
