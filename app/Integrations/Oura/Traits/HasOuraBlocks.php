<?php

namespace App\Integrations\Oura\Traits;

use App\Integrations\Oura\OuraPlugin;
use App\Models\Event;

trait HasOuraBlocks
{
    /**
     * Create contributor blocks for scores
     */
    protected function createContributorBlocks(Event $event, array $contributors, OuraPlugin $plugin): void
    {
        foreach ($contributors as $name => $value) {
            [$encodedContrib, $contribMultiplier] = $plugin->encodeNumericValue($value);
            $event->createBlock([
                'block_type' => 'contributor',
                'time' => $event->time,
                'integration_id' => $event->integration_id,
                'title' => ucwords(str_replace('_', ' ', $name)),
                'metadata' => ['type' => 'contributor', 'field' => $name],
                'value' => $encodedContrib,
                'value_multiplier' => $contribMultiplier,
                'value_unit' => 'percent',
            ]);
        }
    }

    /**
     * Create activity metric blocks
     */
    protected function createActivityMetricBlocks(Event $event, array $item, array $metrics, OuraPlugin $plugin): void
    {
        foreach ($metrics as $field => $config) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $event->createBlock([
                    'block_type' => 'activity_metric',
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => $config['title'],
                    'metadata' => [
                        'type' => $config['type'] ?? 'metric',
                        'field' => $field,
                        'category' => $config['category'] ?? 'general',
                    ],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => $config['unit'],
                ]);
            }
        }
    }

    /**
     * Create sleep stage blocks
     */
    protected function createSleepStageBlocks(Event $event, array $item, array $metrics, OuraPlugin $plugin): void
    {
        foreach ($metrics as $field => $config) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $event->createBlock([
                    'block_type' => 'sleep_stage',
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => $config['title'],
                    'metadata' => [
                        'type' => $config['type'] ?? 'sleep_metric',
                        'field' => $field,
                        'category' => $config['category'] ?? 'duration',
                    ],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => $config['unit'],
                ]);
            }
        }
    }

    /**
     * Create sleep timing blocks (bedtime, wake up time, etc)
     */
    protected function createSleepTimingBlocks(Event $event, array $item, array $fields, OuraPlugin $plugin): void
    {
        foreach ($fields as $field => $title) {
            $value = $item[$field] ?? null;
            if ($value) {
                $event->createBlock([
                    'block_type' => 'sleep_stage',
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => $title,
                    'metadata' => [
                        'type' => 'timing',
                        'field' => $field,
                        'value' => $value,
                    ],
                ]);
            }
        }
    }

    /**
     * Create biometric blocks (temperature, HRV, etc)
     */
    protected function createBiometricBlocks(Event $event, array $item, array $metrics, OuraPlugin $plugin): void
    {
        foreach ($metrics as $field => $config) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $event->createBlock([
                    'block_type' => 'biometrics',
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => $config['title'],
                    'metadata' => [
                        'type' => $config['type'] ?? 'biometric',
                        'field' => $field,
                    ],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => $config['unit'],
                ]);
            }
        }
    }

    /**
     * Create heart rate blocks (min/max/avg)
     */
    protected function createHeartRateBlocks(Event $event, array $heartRateData, OuraPlugin $plugin): void
    {
        $heartRateTypes = [
            'min' => 'minimum',
            'max' => 'maximum',
            'avg' => 'average',
            'average' => 'average',
            'resting' => 'resting',
        ];

        foreach ($heartRateData as $type => $value) {
            if ($value !== null && isset($heartRateTypes[$type])) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $event->createBlock([
                    'block_type' => 'heart_rate',
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => ucfirst($heartRateTypes[$type]) . ' Heart Rate',
                    'metadata' => [
                        'type' => $heartRateTypes[$type],
                        'context' => 'daily_summary',
                    ],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => 'bpm',
                ]);
            }
        }
    }

    /**
     * Create workout metric blocks
     */
    protected function createWorkoutMetricBlocks(Event $event, array $item, array $metrics, OuraPlugin $plugin): void
    {
        foreach ($metrics as $field => $config) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $event->createBlock([
                    'block_type' => 'workout_metric',
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => $config['title'],
                    'metadata' => [
                        'type' => $config['type'] ?? 'workout_metric',
                        'field' => $field,
                        'estimated' => $config['estimated'] ?? false,
                    ],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => $config['unit'],
                ]);
            }
        }
    }

    /**
     * Create tag info blocks
     */
    protected function createTagInfoBlocks(Event $event, array $item, array $fields, OuraPlugin $plugin): void
    {
        foreach ($fields as $field => $title) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                $event->createBlock([
                    'block_type' => 'tag_info',
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => $title,
                    'metadata' => [
                        'type' => 'tag_detail',
                        'field' => $field,
                    ],
                    'content' => is_array($value) ? json_encode($value) : (string) $value,
                ]);
            }
        }
    }

    /**
     * Get standard activity metrics configuration
     */
    protected function getStandardActivityMetrics(): array
    {
        return [
            'steps' => [
                'unit' => 'count',
                'title' => 'Steps',
                'type' => 'core_metric',
                'category' => 'movement',
            ],
            'cal_total' => [
                'unit' => 'kcal',
                'title' => 'Total Calories',
                'type' => 'core_metric',
                'category' => 'energy',
            ],
            'active_calories' => [
                'unit' => 'kcal',
                'title' => 'Active Calories',
                'type' => 'core_metric',
                'category' => 'energy',
            ],
            'equivalent_walking_distance' => [
                'unit' => 'meters',
                'title' => 'Walking Distance',
                'type' => 'core_metric',
                'category' => 'distance',
            ],
            'target_calories' => [
                'unit' => 'kcal',
                'title' => 'Target Calories',
                'type' => 'target_metric',
                'category' => 'goal',
            ],
            'non_wear_time' => [
                'unit' => 'seconds',
                'title' => 'Non-Wear Time',
                'type' => 'time_metric',
                'category' => 'usage',
            ],
        ];
    }

    /**
     * Get MET activity metrics configuration
     */
    protected function getMetActivityMetrics(): array
    {
        return [
            'average_met_minutes' => [
                'unit' => 'met_minutes',
                'title' => 'Average MET Minutes',
                'type' => 'met_metric',
                'category' => 'intensity',
            ],
            'high_activity_met_minutes' => [
                'unit' => 'met_minutes',
                'title' => 'High Activity MET',
                'type' => 'met_metric',
                'category' => 'intensity',
            ],
            'low_activity_met_minutes' => [
                'unit' => 'met_minutes',
                'title' => 'Low Activity MET',
                'type' => 'met_metric',
                'category' => 'intensity',
            ],
            'medium_activity_met_minutes' => [
                'unit' => 'met_minutes',
                'title' => 'Medium Activity MET',
                'type' => 'met_metric',
                'category' => 'intensity',
            ],
            'sedentary_met_minutes' => [
                'unit' => 'met_minutes',
                'title' => 'Sedentary MET',
                'type' => 'met_metric',
                'category' => 'intensity',
            ],
        ];
    }

    /**
     * Get sleep stage metrics configuration
     */
    protected function getStandardSleepMetrics(): array
    {
        return [
            'total_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'Total Sleep Duration',
                'type' => 'sleep_metric',
                'category' => 'duration',
            ],
            'sleep_efficiency' => [
                'unit' => 'percent',
                'title' => 'Sleep Efficiency',
                'type' => 'sleep_metric',
                'category' => 'quality',
            ],
            'restfulness' => [
                'unit' => 'percent',
                'title' => 'Restfulness',
                'type' => 'sleep_metric',
                'category' => 'quality',
            ],
            'timing' => [
                'unit' => 'score',
                'title' => 'Sleep Timing Score',
                'type' => 'sleep_metric',
                'category' => 'timing',
            ],
            'latency' => [
                'unit' => 'seconds',
                'title' => 'Sleep Latency',
                'type' => 'sleep_metric',
                'category' => 'onset',
            ],
            'deep_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'Deep Sleep Duration',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
            'light_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'Light Sleep Duration',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
            'rem_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'REM Sleep Duration',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
            'awake_time' => [
                'unit' => 'seconds',
                'title' => 'Awake Time',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
        ];
    }

    /**
     * Get sleep timing fields
     */
    protected function getStandardSleepTimingFields(): array
    {
        return [
            'bedtime_start' => 'Bedtime Start',
            'bedtime_end' => 'Bedtime End',
            'wake_up_time' => 'Wake Up Time',
        ];
    }

    /**
     * Create recommendation blocks
     */
    protected function createRecommendationBlocks(Event $event, array $item, array $fields, OuraPlugin $plugin): void
    {
        foreach ($fields as $field => $title) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                $event->createBlock([
                    'block_type' => 'recommendation',
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => $title,
                    'metadata' => [
                        'type' => str_replace(' ', '_', strtolower($title)),
                        'field' => $field,
                        'value' => is_array($value) ? $value : null,
                    ],
                ]);
            }
        }
    }
}
