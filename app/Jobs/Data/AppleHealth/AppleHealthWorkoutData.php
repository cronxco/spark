<?php

namespace App\Jobs\Data\AppleHealth;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Arr;

class AppleHealthWorkoutData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'apple_health';
    }

    protected function getJobType(): string
    {
        return 'workouts';
    }

    protected function process(): void
    {
        $workout = $this->rawData;
        $eventData = $this->mapWorkoutToEvent($workout);

        if ($eventData) {
            $this->createEvents([$eventData]);
        }
    }

    private function mapWorkoutToEvent(array $workout): ?array
    {
        $id = (string) (Arr::get($workout, 'id') ?? md5(json_encode([
            $this->integration->id,
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
            'domain' => 'health',
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
