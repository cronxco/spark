<?php

namespace App\Integrations\AppleHealth;

use App\Integrations\Base\WebhookPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Support\Arr;

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
        return 'Receive Apple Health exports via webhook. Supports separate instances for workouts and metrics. Workouts are captured with granular blocks.';
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
            ],
            'metrics' => [
                'label' => 'Metrics',
                'schema' => self::getConfigurationSchema(),
                'mandatory' => false,
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
            'completed_workout' => [
                'icon' => 'o-fire',
                'display_name' => 'Completed Workout',
                'description' => 'A workout session that has been completed',
                'display_with_object' => true,
                'value_unit' => 'kcal',
                'hidden' => false,
            ],
            'measurement' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Health Measurement',
                'description' => 'A health metric measurement from Apple Health',
                'display_with_object' => false,
                'value_unit' => null,
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
        ];
    }

    public function initializeGroup(\App\Models\User $user): IntegrationGroup
    {
        // Use parent implementation to generate shared secret + webhook URL
        return parent::initializeGroup($user);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = []): Integration
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
     * Process webhook data for webhook jobs
     */
    public function processWebhookData(array $webhookPayload, array $headers, Integration $integration): array
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

        $instanceType = (string) ($integration->instance_type ?? 'workouts');
        $processingData = [];

        // Extract data from the nested payload structure
        $payloadData = $webhookPayload['payload']['data'] ?? $webhookPayload;

        if ($instanceType === 'workouts') {
            $workouts = is_array($payloadData['workouts'] ?? null) ? $payloadData['workouts'] : [];
            foreach ($workouts as $workout) {
                if (is_array($workout)) {
                    $processingData[] = [
                        'type' => 'workout',
                        'data' => $workout,
                    ];
                }
            }
        }

        if ($instanceType === 'metrics') {
            $metrics = is_array($payloadData['metrics'] ?? null) ? $payloadData['metrics'] : [];
            foreach ($metrics as $metricEntry) {
                if (is_array($metricEntry)) {
                    $processingData[] = [
                        'type' => 'metric',
                        'data' => $metricEntry,
                    ];
                }
            }
        }

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
        // Summary block
        $summaryMetadata = [];
        if ($distanceQty !== null && $distanceUnit) {
            $summaryMetadata['distance'] = "{$distanceQty} {$distanceUnit}";
        }
        if ($duration !== null) {
            $summaryMetadata['duration'] = "{$duration} s";
        }
        if ($energyQty !== null && $energyUnit) {
            $summaryMetadata['active_energy'] = "{$energyQty} {$energyUnit}";
        }
        if ($intensityQty !== null && $intensityUnit) {
            $summaryMetadata['intensity'] = "{$intensityQty} {$intensityUnit}";
        }
        if ($location) {
            $summaryMetadata['location'] = "{$location}";
        }
        if (! empty($summaryMetadata)) {
            $blocks[] = [
                'block_type' => 'summary',
                'time' => $start,
                'title' => 'Summary',
                'metadata' => [$summaryMetadata],
            ];
        }
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

        return [
            'source_id' => 'apple_workout_' . $id,
            'time' => $start,
            'actor' => $actor,
            'target' => $target,
            'domain' => self::getDomain(),
            'action' => 'completed_workout',
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
                'location' => $location,
            ],
            'blocks' => $blocks,
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

        $target = [
            'concept' => 'metric',
            'type' => 'apple_metric',
            'title' => $name,
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
                    'time' => $date,
                    'title' => $label,
                    'metadata' => ['text' => $label . ' value for ' . $name],
                    'value' => $bVal,
                    'value_multiplier' => $bMult,
                    'value_unit' => $unit,
                ];
            }
        }
        if (array_key_exists('source', $point)) {
            $blocks[] = [
                'time' => $date,
                'title' => 'Source',
                'metadata' => ['text' => (string) $point['source']],
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $date,
            'actor' => $actor,
            'target' => $target,
            'domain' => self::getDomain(),
            'action' => 'measurement',
            'value' => $enc,
            'value_multiplier' => $mult,
            'value_unit' => $unit,
            'event_metadata' => [
                'metric' => $name,
                'raw' => $point,
            ],
            'blocks' => $blocks,
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
