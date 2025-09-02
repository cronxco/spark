<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Arr;

class OuraStressData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'stress';
    }

    protected function process(): void
    {
        $stressItems = $this->rawData;

        if (empty($stressItems)) {
            return;
        }

        $events = [];
        foreach ($stressItems as $item) {
            $eventData = $this->createDailyRecordEvent($item, [
                'score_field' => 'stress_score',
                'contributors_field' => 'contributors',
                'title' => 'Stress',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
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

        $sourceId = "oura_stress_{$this->integration->id}_{$day}";
        if ($this->eventExists($sourceId)) {
            return null;
        }

        $actor = $this->createOrUpdateObject([
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => $day . ' 00:00:00',
        ]);

        $target = $this->createOrUpdateObject([
            'concept' => 'metric',
            'type' => 'oura_daily_stress',
            'title' => $options['title'] ?? 'Stress',
            'time' => $day . ' 00:00:00',
            'metadata' => $item,
        ]);

        $scoreField = $options['score_field'] ?? 'score';
        $score = Arr::get($item, $scoreField);
        [$encodedScore, $scoreMultiplier] = $this->encodeNumericValue(is_numeric($score) ? (float) $score : null);

        $contributorsField = $options['contributors_field'] ?? null;
        $contributors = $contributorsField ? Arr::get($item, $contributorsField, []) : [];

        $blocks = [];
        foreach ($contributors as $name => $value) {
            [$encodedContrib, $contribMultiplier] = $this->encodeNumericValue(is_numeric($value) ? (float) $value : null);
            $blocks[] = [
                'time' => $day . ' 00:00:00',
                'title' => str_replace('_', ' ', ucfirst($name)),
                'metadata' => ['text' => 'Contributor score'],
                'value' => $encodedContrib,
                'value_multiplier' => $contribMultiplier,
                'value_unit' => $options['contributors_value_unit'] ?? $options['value_unit'] ?? 'score',
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'had_stress_score',
            'value' => $encodedScore,
            'value_multiplier' => $scoreMultiplier,
            'value_unit' => $options['value_unit'] ?? 'score',
            'event_metadata' => [
                'day' => $day,
                'kind' => 'stress',
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
