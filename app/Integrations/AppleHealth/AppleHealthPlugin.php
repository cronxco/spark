<?php

namespace App\Integrations\AppleHealth;

use App\Integrations\Base\WebhookPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class AppleHealthPlugin extends WebhookPlugin
{
    public static function getIdentifier(): string
    {
        return 'apple_health';
    }

    public static function getDisplayName(): string
    {
        return 'Apple Health';
    }

    public static function getDescription(): string
    {
        return 'Sync workouts and health metrics from Apple Health.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update frequency (minutes)',
                'default' => 0,
                'min' => 0,
                'max' => 1440,
                'description' => 'Webhook-driven; frequency not used. Leave 0.',
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'workouts' => [
                'label' => 'Workouts',
                'schema' => self::getConfigurationSchema(),
                'mandatory' => false,
                'anomaly_detection_mode' => 'realtime',
            ],
            'metrics' => [
                'label' => 'Metrics',
                'schema' => self::getConfigurationSchema(),
                'mandatory' => false,
                'anomaly_detection_mode' => 'retrospective',
            ],
        ];
    }

    public static function getServiceType(): string
    {
        return 'webhook';
    }

    public static function getIcon(): string
    {
        return 'o-heart';
    }

    public static function getAccentColor(): string
    {
        return 'success';
    }

    public static function getDomain(): string
    {
        return 'health';
    }

    public static function getActionTypes(): array
    {
        return [
            'did_workout' => [
                'icon' => 'o-fire',
                'display_name' => 'Did Workout',
                'description' => 'A workout session completed by the user',
                'display_with_object' => false,
                'value_unit' => 'kcal',
                'value_formatter' => '{{ round($value) }} kcal',
                'hidden' => false,
            ],
            // Health Metrics
            'had_environmental_audio_exposure' => [
                'icon' => 'o-musical-note',
                'display_name' => 'Had Environmental Audio Exposure',
                'description' => 'Environmental audio exposure measurement',
                'display_with_object' => false,
                'value_unit' => 'dB',
                'value_formatter' => '{{ round($value) }} dB',
                'hidden' => false,
            ],
            'had_heart_rate' => [
                'icon' => 'o-heart',
                'display_name' => 'Had Heart Rate',
                'description' => 'Heart rate measurement',
                'display_with_object' => false,
                'value_unit' => 'bpm',
                'value_formatter' => '{{ round($value) }} bpm',
                'hidden' => false,
            ],
            'had_walking_speed' => [
                'icon' => 'o-arrow-right',
                'display_name' => 'Had Walking Speed',
                'description' => 'Walking speed measurement',
                'display_with_object' => false,
                'value_unit' => 'km/h',
                'value_formatter' => '{{ number_format($value, 2) }} km/h',
                'hidden' => false,
            ],
            'had_walking_heart_rate_average' => [
                'icon' => 'o-heart',
                'display_name' => 'Had Walking Heart Rate Average',
                'description' => 'Average heart rate while walking',
                'display_with_object' => false,
                'value_unit' => 'bpm',
                'value_formatter' => '{{ round($value) }} bpm',
                'hidden' => false,
            ],
            'had_basal_energy_burned' => [
                'icon' => 'o-fire',
                'display_name' => 'Had Basal Energy Burned',
                'description' => 'Basal energy expenditure measurement',
                'display_with_object' => false,
                'value_unit' => 'kcal',
                'value_formatter' => '{{ round($value) }} kcal',
                'hidden' => false,
            ],
            'had_resting_heart_rate' => [
                'icon' => 'o-heart',
                'display_name' => 'Had Resting Heart Rate',
                'description' => 'Resting heart rate measurement',
                'display_with_object' => false,
                'value_unit' => 'bpm',
                'value_formatter' => '{{ round($value) }} bpm',
                'hidden' => false,
            ],
            'had_breathing_disturbances' => [
                'icon' => 'o-cloud',
                'display_name' => 'Had Breathing Disturbances',
                'description' => 'Sleep breathing disturbances measurement',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
            'had_stair_speed_down' => [
                'icon' => 'o-arrow-down',
                'display_name' => 'Had Stair Speed Down',
                'description' => 'Speed going down stairs',
                'display_with_object' => false,
                'value_unit' => 'steps/s',
                'value_formatter' => '{{ number_format($value, 2) }} steps/s',
                'hidden' => false,
            ],
            'had_headphone_audio_exposure' => [
                'icon' => 'o-speaker-wave',
                'display_name' => 'Had Headphone Audio Exposure',
                'description' => 'Audio exposure through headphones',
                'display_with_object' => false,
                'value_unit' => 'dB',
                'value_formatter' => '{{ round($value) }} dB',
                'hidden' => false,
            ],
            'had_active_energy' => [
                'icon' => 'o-bolt',
                'display_name' => 'Had Active Energy',
                'description' => 'Active energy burned measurement',
                'display_with_object' => false,
                'value_unit' => 'kcal',
                'value_formatter' => '{{ round($value) }} kcal',
                'hidden' => false,
            ],
            'had_flights_climbed' => [
                'icon' => 'o-arrow-up',
                'display_name' => 'Had Flights Climbed',
                'description' => 'Number of flights of stairs climbed',
                'display_with_object' => false,
                'value_unit' => 'flights',
                'value_formatter' => '{{ round($value) }} flights',
                'hidden' => false,
            ],
            'had_walking_step_length' => [
                'icon' => 'o-arrow-right',
                'display_name' => 'Had Walking Step Length',
                'description' => 'Average length of walking steps',
                'display_with_object' => false,
                'value_unit' => 'cm',
                'value_formatter' => '{{ number_format($value, 1) }} cm',
                'hidden' => false,
            ],
            'had_stair_speed_up' => [
                'icon' => 'o-arrow-up',
                'display_name' => 'Had Stair Speed Up',
                'description' => 'Speed going up stairs',
                'display_with_object' => false,
                'value_unit' => 'steps/s',
                'value_formatter' => '{{ number_format($value, 2) }} steps/s',
                'hidden' => false,
            ],
            'had_walking_asymmetry_percentage' => [
                'icon' => 'o-scale',
                'display_name' => 'Had Walking Asymmetry Percentage',
                'description' => 'Walking gait asymmetry measurement',
                'display_with_object' => false,
                'value_unit' => 'percent',
                'value_formatter' => '{{ round($value) }}<span class="text-[0.875em]">%</span>',
                'hidden' => false,
            ],
            'had_apple_sleeping_wrist_temperature' => [
                'icon' => 'o-sun',
                'display_name' => 'Had Apple Sleeping Wrist Temperature',
                'description' => 'Wrist temperature during sleep',
                'display_with_object' => false,
                'value_unit' => '°C',
                'value_formatter' => '{{ number_format($value, 1) }}°C',
                'hidden' => false,
            ],
            'had_walking_double_support_percentage' => [
                'icon' => 'o-scale',
                'display_name' => 'Had Walking Double Support Percentage',
                'description' => 'Percentage of walking cycle with both feet on ground',
                'display_with_object' => false,
                'value_unit' => 'percent',
                'value_formatter' => '{{ round($value) }}<span class="text-[0.875em]">%</span>',
                'hidden' => false,
            ],
            'had_vo2_max' => [
                'icon' => 'o-heart',
                'display_name' => 'Had VO2 Max',
                'description' => 'Maximum oxygen consumption measurement',
                'display_with_object' => false,
                'value_unit' => 'mL/kg/min',
                'value_formatter' => '{{ number_format($value, 1) }} ml·kg<sup>-1</sup>·min<sup>-1</sup>',
                'hidden' => false,
            ],
            'had_respiratory_rate' => [
                'icon' => 'o-cloud',
                'display_name' => 'Had Respiratory Rate',
                'description' => 'Breathing rate measurement',
                'display_with_object' => false,
                'value_unit' => 'breaths/min',
                'value_formatter' => '{{ round($value) }} breaths/min',
                'hidden' => false,
            ],
            'had_apple_exercise_time' => [
                'icon' => 'o-clock',
                'display_name' => 'Had Apple Exercise Time',
                'description' => 'Exercise time tracked by Apple Watch',
                'display_with_object' => false,
                'value_unit' => 'min',
                'value_formatter' => '{{ format_duration($value * 60) }}',
                'hidden' => false,
            ],
            'had_time_in_daylight' => [
                'icon' => 'o-sun',
                'display_name' => 'Had Time in Daylight',
                'description' => 'Time spent in daylight',
                'display_with_object' => false,
                'value_unit' => 'min',
                'value_formatter' => '{{ format_duration($value * 60) }}',
                'hidden' => false,
            ],
            'had_walking_running_distance' => [
                'icon' => 'o-map',
                'display_name' => 'Had Walking + Running Distance',
                'description' => 'Distance covered walking and running',
                'display_with_object' => false,
                'value_unit' => 'km',
                'value_formatter' => '{{ number_format($value, 2) }} km',
                'hidden' => false,
            ],
            'had_physical_effort' => [
                'icon' => 'o-bolt',
                'display_name' => 'Had Physical Effort',
                'description' => 'Physical effort measurement',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
            'had_step_count' => [
                'icon' => 'o-cursor-arrow-rays',
                'display_name' => 'Had Step Count',
                'description' => 'Number of steps taken',
                'display_with_object' => false,
                'value_unit' => 'steps',
                'value_formatter' => '{{ number_format($value, 0) }} steps',
                'hidden' => false,
            ],
            'had_blood_oxygen_saturation' => [
                'icon' => 'o-beaker',
                'display_name' => 'Had Blood Oxygen Saturation',
                'description' => 'Blood oxygen saturation level',
                'display_with_object' => false,
                'value_unit' => 'percent',
                'value_formatter' => '{{ round($value) }}<span class="text-[0.875em]">%</span>',
                'hidden' => false,
            ],
            'had_heart_rate_variability' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Had Heart Rate Variability',
                'description' => 'Heart rate variability measurement',
                'display_with_object' => false,
                'value_unit' => 'ms',
                'value_formatter' => '{{ round($value) }} ms',
                'hidden' => false,
            ],
            'had_apple_stand_hour' => [
                'icon' => 'o-arrow-up-circle',
                'display_name' => 'Had Apple Stand Hour',
                'description' => 'Stand hours tracked by Apple Watch',
                'display_with_object' => false,
                'value_unit' => 'hours',
                'value_formatter' => '{{ round($value) }} hours',
                'hidden' => false,
            ],
            'had_six_minute_walking_test_distance' => [
                'icon' => 'o-map',
                'display_name' => 'Had Six Minute Walking Test Distance',
                'description' => 'Distance covered in 6-minute walking test',
                'display_with_object' => false,
                'value_unit' => 'm',
                'value_formatter' => '{{ number_format($value, 1) }} m',
                'hidden' => false,
            ],
            'had_apple_stand_time' => [
                'icon' => 'o-clock',
                'display_name' => 'Had Apple Stand Time',
                'description' => 'Stand time tracked by Apple Watch',
                'display_with_object' => false,
                'value_unit' => 'min',
                'value_formatter' => '{{ format_duration($value * 60) }}',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [];
    }

    public static function getObjectTypes(): array
    {
        return [
            'apple_health_user' => [
                'icon' => 'o-heart',
                'display_name' => 'Apple Health User',
                'description' => 'User profile in Apple Health',
                'hidden' => true,
            ],
            'apple_metric' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Apple Metric',
                'description' => 'Health metric from Apple Health',
                'hidden' => true,
            ],
            'apple_workout' => [
                'icon' => 'o-fire',
                'display_name' => 'Apple Workout',
                'description' => 'Workout from Apple Health',
                'hidden' => true,
            ],
            'workout' => [
                'icon' => 'o-fire',
                'display_name' => 'Workout',
                'description' => 'Workout data type',
                'hidden' => true,
            ],
            'metric' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Metric',
                'description' => 'Metric data type',
                'hidden' => true,
            ],
        ];
    }

    public function initializeGroup(\App\Models\User $user): IntegrationGroup
    {
        // Use parent implementation to generate shared secret + webhook URL
        return parent::initializeGroup($user);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = [], bool $withMigration = false): Integration
    {
        $integration = parent::createInstance($group, $instanceType, $initialConfig);

        // For webhook services, set the account_id to the group's account_id for webhook routing
        $integration->update(['account_id' => $group->account_id]);

        return $integration;
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        $instanceType = (string) ($integration->instance_type ?? 'workouts');
        $events = [];

        // Extract data from the nested payload structure
        $payloadData = $externalData['payload']['data'] ?? $externalData;

        // Accept either a top-level {workouts:[...]} or {metrics:[...]} payload
        if ($instanceType === 'workouts') {
            $workouts = is_array($payloadData['workouts'] ?? null) ? $payloadData['workouts'] : [];
            foreach ($workouts as $workout) {
                if (! is_array($workout)) {
                    continue;
                }
                $events[] = $this->mapWorkoutToEvent($workout, $integration);
            }
        }

        if ($instanceType === 'metrics') {
            $metrics = is_array($payloadData['metrics'] ?? null) ? $payloadData['metrics'] : [];
            foreach ($metrics as $metricEntry) {
                if (! is_array($metricEntry)) {
                    continue;
                }
                $name = (string) ($metricEntry['name'] ?? 'unknown_metric');
                $unit = $metricEntry['units'] ?? null;
                $dataPoints = is_array($metricEntry['data'] ?? null) ? $metricEntry['data'] : [];
                foreach ($dataPoints as $point) {
                    if (! is_array($point)) {
                        continue;
                    }
                    $events[] = $this->mapMetricPointToEvent($name, $unit, $point, $integration);
                }
            }
        }

        return ['events' => array_values(array_filter($events))];
    }

    /**
     * Validate webhook signature for webhook jobs
     */
    public function validateWebhookSignature(array $webhookPayload, array $headers, Integration $integration): void
    {
        // Get the secret from the route parameter
        $routeSecret = $headers['x-webhook-secret'][0] ?? null;

        // Get the expected secret from the integration's account_id
        $expectedSecret = $integration->account_id;

        // Perform constant-time comparison to prevent timing attacks
        if (empty($routeSecret) || empty($expectedSecret)) {
            throw new Exception('Missing webhook secret');
        }

        if (! hash_equals($expectedSecret, $routeSecret)) {
            throw new Exception('Invalid webhook secret');
        }
    }

    /**
     * Process webhook data for webhook jobs
     */
    public function processWebhookData(array $webhookPayload, array $headers, Integration $integration): array
    {
        $instanceType = (string) ($integration->instance_type ?? 'workouts');
        $processingData = [];

        // Extract data from the nested payload structure (robust to variations)
        $payloadData = $webhookPayload['payload']['data']
            ?? ($webhookPayload['data'] ?? $webhookPayload);

        // Try multiple common shapes
        $workouts = $payloadData['workouts']
            ?? ($webhookPayload['payload']['data']['workouts'] ?? ($webhookPayload['data']['workouts'] ?? ($webhookPayload['workouts'] ?? [])));
        $metrics = $payloadData['metrics']
            ?? ($webhookPayload['payload']['data']['metrics'] ?? ($webhookPayload['data']['metrics'] ?? ($webhookPayload['metrics'] ?? [])));

        // Normalize to arrays
        $workouts = is_array($workouts) ? $workouts : [];
        $metrics = is_array($metrics) ? $metrics : [];

        // Respect explicit instance_type only to avoid cross-processing duplicates
        if ($instanceType === 'workouts') {
            foreach ($workouts as $workout) {
                if (is_array($workout)) {
                    $processingData[] = [
                        'type' => 'workout',
                        'data' => $workout,
                    ];
                }
            }
        } elseif ($instanceType === 'metrics') {
            foreach ($metrics as $metricEntry) {
                if (is_array($metricEntry)) {
                    $processingData[] = [
                        'type' => 'metric',
                        'data' => $metricEntry,
                    ];
                }
            }
        }

        // Diagnostics
        Log::info('AppleHealthPlugin.processWebhookData split', [
            'integration_id' => $integration->id,
            'instance_type' => $instanceType,
            'workouts_in_payload' => is_countable($workouts) ? count($workouts) : 0,
            'metrics_in_payload' => is_countable($metrics) ? count($metrics) : 0,
            'initial_chunks' => is_countable($processingData) ? count($processingData) : 0,
            'top_level_keys' => array_slice(array_keys($webhookPayload), 0, 10),
            'has_payload_key' => array_key_exists('payload', $webhookPayload),
            'payload_has_data_key' => isset($webhookPayload['payload']) && is_array($webhookPayload['payload']) && array_key_exists('data', $webhookPayload['payload']),
        ]);

        // No fallback: if instance type doesn't match payload, we intentionally do nothing here

        return $processingData;
    }

    public function mapWorkoutToEvent(array $workout, Integration $integration): array
    {
        $id = (string) (Arr::get($workout, 'id') ?? md5(json_encode([
            $integration->id,
            Arr::get($workout, 'start'),
            Arr::get($workout, 'name'),
        ])));
        $name = (string) (Arr::get($workout, 'name') ?? 'Workout');
        // Strip indoor/outdoor prefixes for cleaner titles
        $lower = strtolower($name);
        if (str_starts_with($lower, 'indoor ')) {
            $name = substr($name, 7);
        } elseif (str_starts_with($lower, 'outdoor ')) {
            $name = substr($name, 8);
        }
        $start = (string) (Arr::get($workout, 'start') ?? Arr::get($workout, 'startDate') ?? now()->toIso8601String());
        $end = (string) (Arr::get($workout, 'end') ?? Arr::get($workout, 'endDate') ?? $start);
        $duration = Arr::get($workout, 'duration');
        $distanceQty = Arr::get($workout, 'distance.qty');
        $distanceUnit = Arr::get($workout, 'distance.units');
        $energyQty = Arr::get($workout, 'activeEnergyBurned.qty');
        $energyUnit = Arr::get($workout, 'activeEnergyBurned.units', 'kcal');
        $intensityQty = Arr::get($workout, 'intensity.qty');
        $intensityUnit = Arr::get($workout, 'intensity.units');
        $location = Arr::get($workout, 'location');

        [$encEnergy, $energyMult] = $this->encodeNumericValue($energyQty);

        $actor = [
            'concept' => 'user',
            'type' => 'apple_health_user',
            'title' => 'Apple Health',
            'time' => $start,
        ];

        $target = [
            'concept' => 'workout',
            'type' => 'apple_workout',
            'title' => $name,
            'time' => $start,
            'metadata' => $workout,
        ];

        $blocks = [];
        // Distance block
        if ($distanceQty !== null && $distanceUnit) {
            [$encDistance, $distMult] = $this->encodeNumericValue($distanceQty);
            $blocks[] = [
                'block_type' => 'distance',
                'time' => $start,
                'title' => 'Distance',
                'metadata' => [],
                'value' => $encDistance,
                'value_multiplier' => $distMult,
                'value_unit' => $distanceUnit,
            ];
        }
        // Energy block
        if ($encEnergy !== null) {
            $blocks[] = [
                'block_type' => 'energy',
                'time' => $start,
                'title' => 'Active Energy',
                'metadata' => [],
                'value' => $encEnergy,
                'value_multiplier' => $energyMult,
                'value_unit' => $energyUnit,
            ];
        }
        // Intensity block
        if ($intensityQty !== null && $intensityUnit) {
            [$encIntensity, $intMult] = $this->encodeNumericValue($intensityQty);
            $blocks[] = [
                'block_type' => 'intensity',
                'time' => $start,
                'title' => 'Intensity',
                'metadata' => [],
                'value' => $encIntensity,
                'value_multiplier' => $intMult,
                'value_unit' => $intensityUnit,
            ];
        }
        // Duration block
        if ($duration !== null) {
            [$encDur, $durMult] = $this->encodeNumericValue($duration);
            $blocks[] = [
                'block_type' => 'duration',
                'time' => $start,
                'title' => 'Duration',
                'metadata' => [],
                'value' => $encDur,
                'value_multiplier' => $durMult,
                'value_unit' => 's',
            ];
        }

        $tags = [];
        if ($location) {
            $tags[] = [
                'name' => (string) $location,
                'type' => 'workout_location',
            ];
        }

        return [
            'source_id' => 'apple_workout_' . $id,
            'time' => $start,
            'actor' => $actor,
            'target' => $target,
            'domain' => self::getDomain(),
            'action' => 'did_workout',
            'value' => $encEnergy,
            'value_multiplier' => $energyMult,
            'value_unit' => $energyUnit,
            'event_metadata' => [
                'end' => $end,
                'duration_seconds' => $duration,
                'distance' => $distanceQty,
                'distance_unit' => $distanceUnit,
                'intensity' => $intensityQty,
                'intensity_unit' => $intensityUnit,
            ],
            'blocks' => $blocks,
            'tags' => $tags,
        ];
    }

    public function mapMetricPointToEvent(string $name, ?string $unit, array $point, Integration $integration): array
    {
        $date = (string) (Arr::get($point, 'date') ?? now()->toDateString());
        $sourceId = 'apple_metric_' . $name . '_' . str_replace([' ', ':', '+'], ['_', '', ''], $date);

        // Prefer Avg if present for series like heart_rate
        $value = Arr::get($point, 'Avg');
        if ($value === null) {
            $value = Arr::get($point, 'qty');
        }
        [$enc, $mult] = $this->encodeNumericValue($value);

        $actor = [
            'concept' => 'user',
            'type' => 'apple_health_user',
            'title' => 'Apple Health',
            'time' => $date,
        ];

        // Convert metric name to Title Case for object title
        $metricTitle = str_replace('_', ' ', $name);
        $metricTitle = ucwords($metricTitle);

        $target = [
            'concept' => 'metric',
            'type' => 'apple_metric',
            'title' => $metricTitle,
            'time' => $date,
        ];

        $blocks = [];
        // If there are Min/Max/Avg, capture them as blocks
        $statMap = [
            'Min' => 'Minimum',
            'Avg' => 'Average',
            'Max' => 'Maximum',
        ];
        foreach ($statMap as $key => $label) {
            if (array_key_exists($key, $point)) {
                [$bVal, $bMult] = $this->encodeNumericValue($point[$key]);
                $blocks[] = [
                    'block_type' => $name,
                    'time' => $date,
                    'title' => $label,
                    'metadata' => [
                        'statistic' => strtolower($label),
                        'metric' => $name,
                        'original_key' => $key,
                    ],
                    'value' => $bVal,
                    'value_multiplier' => $bMult,
                    'value_unit' => $unit,
                ];
            }
        }
        $tags = [];
        if (array_key_exists('source', $point) && is_string($point['source']) && $point['source'] !== '') {
            $tags[] = [
                'name' => (string) $point['source'],
                'type' => 'workout_data_source',
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $date,
            'actor' => $actor,
            'target' => $target,
            'domain' => self::getDomain(),
            'action' => 'had_' . $name,
            'value' => $enc,
            'value_multiplier' => $mult,
            'value_unit' => $unit,
            'event_metadata' => [
                'metric' => $name,
                'raw' => $point,
            ],
            'blocks' => $blocks,
            'tags' => $tags,
        ];
    }

    /**
     * Encode a numeric value into an integer with a multiplier to retain precision.
     * Returns [encodedInt|null, multiplier|null].
     */
    private function encodeNumericValue(null|int|float|string $raw, int $defaultMultiplier = 1): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }
        $float = (float) $raw;
        if (! is_finite($float)) {
            return [null, null];
        }
        if (fmod($float, 1.0) !== 0.0) {
            $multiplier = 1000;
            $intValue = (int) round($float * $multiplier);

            return [$intValue, $multiplier];
        }

        return [(int) $float, $defaultMultiplier];
    }
}
