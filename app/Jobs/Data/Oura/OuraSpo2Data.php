<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Arr;

class OuraSpo2Data extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'spo2';
    }

    protected function process(): void
    {
        $spo2Items = $this->rawData;

        if (empty($spo2Items)) {
            return;
        }

        $events = [];
        foreach ($spo2Items as $item) {
            $eventData = $this->createDailyRecordEvent($item, [
                'score_field' => 'spo2_average',
                'contributors_field' => null,
                'title' => 'SpO2',
                'value_unit' => 'percent',
            ]);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            $this->createEvents($events);
        }
    }

    private function createDailyRecordEvent(array $item, array $options): ?array
    {
        $day = $item['day'] ?? $item['date'] ?? null;
        if (! $day) {
            return null;
        }

        $sourceId = "oura_spo2_{$this->integration->id}_{$day}";

        $actor = [
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => $day . ' 00:00:00',
        ];

        $target = [
            'concept' => 'metric',
            'type' => 'oura_daily_spo2',
            'title' => $options['title'] ?? 'SpO2',
            'time' => $day . ' 00:00:00',
            'metadata' => $item,
        ];

        $scoreField = $options['score_field'] ?? 'score';
        $score = Arr::get($item, $scoreField);
        [$encodedScore, $scoreMultiplier] = $this->encodeNumericValue(is_numeric($score) ? (float) $score : null);

        return [
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'had_spo2',
            'value' => $encodedScore,
            'value_multiplier' => $scoreMultiplier,
            'value_unit' => $options['value_unit'] ?? 'score',
            'event_metadata' => [
                'day' => $day,
                'kind' => 'spo2',
            ],
            'blocks' => [],
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
