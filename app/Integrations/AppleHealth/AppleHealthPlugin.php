<?php

namespace App\Integrations\AppleHealth;

use App\Integrations\Base\WebhookPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Support\Arr;

class AppleHealthPlugin extends WebhookPlugin
{
    public static function getIdentifier(): string
    {
        return 'apple-health';
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
            ],
            'metrics' => [
                'label' => 'Metrics',
                'schema' => self::getConfigurationSchema(),
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
        return parent::createInstance($group, $instanceType, $initialConfig);
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        $events = [];

        // Process workouts data if present in payload
        if (isset($externalData['workouts']) && is_array($externalData['workouts'])) {
            foreach ($externalData['workouts'] as $workout) {
                if (! is_array($workout)) {
                    continue;
                }
                $events[] = $this->mapWorkoutToEvent($workout, $integration);
            }
        }

        // Process metrics data if present in payload
        if (isset($externalData['metrics']) && is_array($externalData['metrics'])) {
            foreach ($externalData['metrics'] as $metricEntry) {
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

    private function mapWorkoutToEvent(array $workout, Integration $integration): array
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
        $summaryLines = [];
        if ($distanceQty !== null && $distanceUnit) {
            $summaryLines[] = "Distance: {$distanceQty} {$distanceUnit}";
        }
        if ($duration !== null) {
            $summaryLines[] = "Duration: {$duration} s";
        }
        if ($energyQty !== null && $energyUnit) {
            $summaryLines[] = "Active energy: {$energyQty} {$energyUnit}";
        }
        if ($intensityQty !== null && $intensityUnit) {
            $summaryLines[] = "Intensity: {$intensityQty} {$intensityUnit}";
        }
        if ($location) {
            $summaryLines[] = "Location: {$location}";
        }
        if (! empty($summaryLines)) {
            $blocks[] = [
                'time' => $start,
                'title' => 'Summary',
                'content' => implode("\n", $summaryLines),
            ];
        }
        // Distance block
        if ($distanceQty !== null && $distanceUnit) {
            [$encDistance, $distMult] = $this->encodeNumericValue($distanceQty);
            $blocks[] = [
                'time' => $start,
                'title' => 'Distance',
                'content' => 'Distance covered in this workout',
                'value' => $encDistance,
                'value_multiplier' => $distMult,
                'value_unit' => $distanceUnit,
            ];
        }
        // Energy block
        if ($encEnergy !== null) {
            $blocks[] = [
                'time' => $start,
                'title' => 'Active Energy',
                'content' => 'Active energy burned during workout',
                'value' => $encEnergy,
                'value_multiplier' => $energyMult,
                'value_unit' => $energyUnit,
            ];
        }
        // Intensity block
        if ($intensityQty !== null && $intensityUnit) {
            [$encIntensity, $intMult] = $this->encodeNumericValue($intensityQty);
            $blocks[] = [
                'time' => $start,
                'title' => 'Intensity',
                'content' => 'Body weight–normalized intensity',
                'value' => $encIntensity,
                'value_multiplier' => $intMult,
                'value_unit' => $intensityUnit,
            ];
        }
        // Duration block
        if ($duration !== null) {
            [$encDur, $durMult] = $this->encodeNumericValue($duration);
            $blocks[] = [
                'time' => $start,
                'title' => 'Duration',
                'content' => 'Workout duration in seconds',
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
            'domain' => 'fitness',
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

    private function mapMetricPointToEvent(string $name, ?string $unit, array $point, Integration $integration): array
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
                    'content' => $label . ' value for ' . $name,
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
                'content' => (string) $point['source'],
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $date,
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
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
