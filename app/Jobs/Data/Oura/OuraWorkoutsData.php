<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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

        if (empty($workouts)) {
            return;
        }

        $events = [];
        foreach ($workouts as $item) {
            $eventData = $this->createWorkoutEvent($item);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            $this->createEvents($events);
        }
    }

    private function createWorkoutEvent(array $item): ?array
    {
        $start = Arr::get($item, 'start_datetime');
        $end = Arr::get($item, 'end_datetime');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $sourceId = "oura_workout_{$this->integration->id}_" . (Arr::get($item, 'id') ?? ($day . '_' . md5(json_encode($item))));

        if ($this->eventExists($sourceId)) {
            return null;
        }

        $actor = $this->createOrUpdateObject([
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => $start ?? ($day . ' 00:00:00'),
        ]);

        $target = $this->createOrUpdateObject([
            'concept' => 'workout',
            'type' => Arr::get($item, 'activity', 'workout'),
            'title' => Str::title((string) Arr::get($item, 'activity', 'Workout')),
            'time' => $start ?? ($day . ' 00:00:00'),
            'metadata' => $item,
        ]);

        $durationSec = (int) Arr::get($item, 'duration', 0);
        $calories = (float) Arr::get($item, 'calories', Arr::get($item, 'total_calories', 0));

        $blocks = [];
        [$encodedCalories, $calMultiplier] = $this->encodeNumericValue($calories);
        $blocks[] = [
            'time' => $start ?? ($day . ' 00:00:00'),
            'title' => 'Calories',
            'metadata' => ['text' => 'Estimated calories for the workout'],
            'value' => $encodedCalories,
            'value_multiplier' => $calMultiplier,
            'value_unit' => 'kcal',
        ];

        $avgHr = Arr::get($item, 'average_heart_rate');
        if ($avgHr !== null) {
            [$encodedAvgHr, $avgHrMultiplier] = $this->encodeNumericValue($avgHr);
            $blocks[] = [
                'time' => $start ?? ($day . ' 00:00:00'),
                'title' => 'Average Heart Rate',
                'metadata' => ['text' => 'Average heart rate during workout'],
                'value' => $encodedAvgHr,
                'value_multiplier' => $avgHrMultiplier,
                'value_unit' => 'bpm',
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'did_workout',
            'value' => $durationSec,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'end' => $end,
                'calories' => $calories,
            ],
            'blocks' => $blocks,
        ];
    }

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
