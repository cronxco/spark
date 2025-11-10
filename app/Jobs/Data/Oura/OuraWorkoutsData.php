<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

class OuraWorkoutsData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'workouts';
    }

    protected function process(): void
    {
        $workouts = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($workouts)) {
            return;
        }

        Log::info('OuraWorkoutsData: Processing workouts', [
            'integration_id' => $this->integration->id,
            'workout_count' => count($workouts),
        ]);

        foreach ($workouts as $item) {
            $this->createEnhancedWorkoutEvent($plugin, $item);
        }

        Log::info('OuraWorkoutsData: Completed processing workouts', [
            'integration_id' => $this->integration->id,
        ]);
    }

    /**
     * Create workout event with full API v2 field support
     */
    private function createEnhancedWorkoutEvent(OuraPlugin $plugin, array $item): void
    {
        $start = $item['start_datetime'] ?? null;
        $end = $item['end_datetime'] ?? null;
        $id = $item['id'] ?? null;

        if (! $start || ! $id) {
            return;
        }

        $sourceId = "oura_workout_{$this->integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)
            ->where('integration_id', $this->integration->id)
            ->first();
        if ($exists) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);
        $activityType = $item['activity'] ?? 'workout';

        // Create workout object only once per workout ID
        $target = EventObject::firstOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'workout',
            'type' => $activityType,
            'title' => ucwords(str_replace('_', ' ', $activityType)) . ' (' . substr($id, 0, 8) . ')',
        ], [
            'time' => $start,
            'content' => 'Enhanced workout session with comprehensive metrics',
            'metadata' => [],
        ]);

        $duration = $item['duration'] ?? 0;
        $calories = $item['calories'] ?? $item['total_calories'] ?? 0;

        [$encodedCalories, $caloriesMultiplier] = $plugin->encodeNumericValue($calories);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $start,
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'did_workout',
            'value' => $encodedCalories,
            'value_multiplier' => $caloriesMultiplier,
            'value_unit' => 'kcal',
            'event_metadata' => [
                'end_datetime' => $end,
                'activity_type' => $activityType,
                'workout_id' => $id,
                'duration_seconds' => $duration,
            ],
            'target_id' => $target->id,
        ]);

        // Add duration block
        if ($duration > 0) {
            [$encodedDuration, $durationMultiplier] = $plugin->encodeNumericValue($duration);
            $event->createBlock([
                'block_type' => 'workout_metric',
                'time' => $event->time,
                'title' => 'Duration',
                'metadata' => ['type' => 'duration', 'field' => 'duration'],
                'value' => $encodedDuration,
                'value_multiplier' => $durationMultiplier,
                'value_unit' => 'seconds',
            ]);
        }

        // Add calorie metrics (only if they differ from the main value)
        $calorieMetrics = [
            'calories' => ['title' => 'Total Calories', 'type' => 'total'],
            'total_calories' => ['title' => 'Total Calories (Enhanced)', 'type' => 'total_enhanced'],
        ];

        foreach ($calorieMetrics as $field => $config) {
            $value = $item[$field] ?? null;
            // Skip if this value is already the main event value
            if ($value !== null && $value != $calories) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $event->createBlock([
                    'block_type' => 'workout_metric',
                    'time' => $event->time,
                    'title' => $config['title'],
                    'metadata' => ['type' => $config['type'], 'field' => $field],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => 'kcal',
                ]);
            }
        }

        // Add heart rate metrics
        $heartRateMetrics = [
            'average_heart_rate' => ['title' => 'Average Heart Rate', 'type' => 'average'],
            'max_heart_rate' => ['title' => 'Maximum Heart Rate', 'type' => 'maximum'],
            'min_heart_rate' => ['title' => 'Minimum Heart Rate', 'type' => 'minimum'],
        ];

        foreach ($heartRateMetrics as $field => $config) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $event->createBlock([
                    'block_type' => 'heart_rate',
                    'time' => $event->time,
                    'title' => $config['title'],
                    'metadata' => ['type' => $config['type'], 'context' => 'workout'],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => 'bpm',
                ]);
            }
        }

        // Add intensity and performance metrics
        $performanceMetrics = [
            'intensity' => ['unit' => 'intensity_level', 'title' => 'Workout Intensity'],
            'load' => ['unit' => 'load_units', 'title' => 'Training Load'],
            'rpe' => ['unit' => 'rpe_scale', 'title' => 'Rate of Perceived Exertion'],
        ];

        foreach ($performanceMetrics as $field => $config) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $event->createBlock([
                    'block_type' => 'workout_metric',
                    'time' => $event->time,
                    'title' => $config['title'],
                    'metadata' => ['type' => 'performance', 'field' => $field],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => $config['unit'],
                ]);
            }
        }

        // Add heart rate zones if present
        $heartRateZones = $item['heart_rate_zones'] ?? null;
        if ($heartRateZones && is_array($heartRateZones)) {
            foreach ($heartRateZones as $zoneName => $zoneData) {
                if (is_array($zoneData) && isset($zoneData['duration'])) {
                    [$encodedDuration, $durationMultiplier] = $plugin->encodeNumericValue($zoneData['duration']);
                    $event->createBlock([
                        'block_type' => 'heart_rate',
                        'time' => $event->time,
                        'title' => 'HR Zone: ' . ucwords(str_replace('_', ' ', $zoneName)),
                        'metadata' => [
                            'type' => 'heart_rate_zone',
                            'zone' => $zoneName,
                            'zone_data' => $zoneData,
                        ],
                        'value' => $encodedDuration,
                        'value_multiplier' => $durationMultiplier,
                        'value_unit' => 'seconds',
                    ]);
                }
            }
        }

        // Add source information
        $source = $item['source'] ?? null;
        if ($source) {
            $event->createBlock([
                'block_type' => 'workout_metric',
                'time' => $event->time,
                'title' => 'Workout Source',
                'metadata' => ['type' => 'source'],
                'content' => $source,
            ]);
        }

        // Add labels if present
        $labels = $item['labels'] ?? null;
        if ($labels && is_array($labels)) {
            $event->createBlock([
                'block_type' => 'workout_metric',
                'time' => $event->time,
                'title' => 'Workout Labels',
                'metadata' => [
                    'type' => 'labels',
                    'labels' => $labels,
                ],
                'content' => implode(', ', $labels),
                'value' => count($labels),
                'value_multiplier' => 1,
                'value_unit' => 'count',
            ]);
        }
    }
}
