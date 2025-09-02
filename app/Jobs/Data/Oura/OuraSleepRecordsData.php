<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class OuraSleepRecordsData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep_records';
    }

    protected function process(): void
    {
        $sleepRecords = $this->rawData;

        if (empty($sleepRecords)) {
            return;
        }

        $events = [];
        foreach ($sleepRecords as $item) {
            $eventData = $this->createSleepRecordEvent($item);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            $this->createEvents($events);
        }
    }

    private function createSleepRecordEvent(array $item): ?array
    {
        $start = Arr::get($item, 'bedtime_start');
        $end = Arr::get($item, 'bedtime_end');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $id = Arr::get($item, 'id') ?? md5(json_encode([$day, Arr::get($item, 'duration', 0), Arr::get($item, 'total', 0)]));
        $sourceId = "oura_sleep_record_{$this->integration->id}_{$id}";

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
            'concept' => 'sleep',
            'type' => 'oura_sleep_record',
            'title' => 'Sleep Record',
            'time' => $start ?? ($day . ' 00:00:00'),
            'metadata' => $item,
        ]);

        $duration = (int) Arr::get($item, 'duration', 0);
        $efficiency = Arr::get($item, 'efficiency');

        $blocks = [];
        $stages = Arr::get($item, 'sleep_stages', []);
        $stageMap = [
            'deep' => 'Deep Sleep',
            'light' => 'Light Sleep',
            'rem' => 'REM Sleep',
            'awake' => 'Awake Time',
        ];
        foreach (['deep', 'light', 'rem', 'awake'] as $stage) {
            $seconds = Arr::get($stages, $stage);
            if ($seconds === null) {
                continue;
            }
            $blocks[] = [
                'time' => $start ?? ($day . ' 00:00:00'),
                'title' => $stageMap[$stage] ?? Str::title($stage) . ' Sleep',
                'metadata' => ['text' => 'Stage duration'],
                'value' => (int) $seconds,
                'value_multiplier' => 1,
                'value_unit' => 'seconds',
            ];
        }

        $hrAvg = Arr::get($item, 'average_heart_rate');
        if ($hrAvg !== null) {
            [$encodedHrAvg, $hrAvgMultiplier] = $this->encodeNumericValue($hrAvg);
            $blocks[] = [
                'time' => $start ?? ($day . ' 00:00:00'),
                'title' => 'Average Heart Rate',
                'metadata' => ['text' => 'Average sleeping heart rate'],
                'value' => $encodedHrAvg,
                'value_multiplier' => $hrAvgMultiplier,
                'value_unit' => 'bpm',
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'slept_for',
            'value' => $duration,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'end' => $end,
                'efficiency' => $efficiency,
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
