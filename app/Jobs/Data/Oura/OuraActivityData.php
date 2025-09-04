<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class OuraActivityData extends BaseProcessingJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    public function getRawData()
    {
        return $this->rawData;
    }

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function process(): void
    {
        $activityItems = $this->rawData;

        if (empty($activityItems)) {
            return;
        }

        $events = [];
        foreach ($activityItems as $item) {
            $eventData = $this->createDailyRecordEvent($item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Activity',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
                'details_fields' => [
                    'steps', 'cal_total', 'equivalent_walking_distance', 'target_calories', 'non_wear_time',
                ],
            ]);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            $this->createEventsSafely($events);
        }
    }

    private function createDailyRecordEvent(array $item, array $options): ?array
    {
        $day = $item['day'] ?? $item['date'] ?? null;
        if (! $day) {
            return null;
        }

        $sourceId = "oura_activity_{$this->integration->id}_{$day}";

        $actor = [
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => $day . ' 00:00:00',
        ];

        $target = [
            'concept' => 'metric',
            'type' => 'oura_daily_activity',
            'title' => $options['title'] ?? 'Activity',
            'time' => $day . ' 00:00:00',
            'metadata' => $item,
        ];

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

        $detailsFields = $options['details_fields'] ?? [];
        $unitMap = [
            'steps' => 'count',
            'cal_total' => 'kcal',
            'equivalent_walking_distance' => 'km',
            'target_calories' => 'kcal',
            'non_wear_time' => 'seconds',
        ];
        foreach ($detailsFields as $field) {
            if (! array_key_exists($field, $item)) {
                continue;
            }
            $label = str_replace('_', ' ', ucfirst($field));
            $value = $item[$field];
            [$encodedDetail, $detailMultiplier] = $this->encodeNumericValue(is_numeric($value) ? (float) $value : null);
            $blocks[] = [
                'time' => $day . ' 00:00:00',
                'title' => $label,
                'metadata' => [],
                'value' => $encodedDetail,
                'value_multiplier' => $detailMultiplier,
                'value_unit' => $unitMap[$field] ?? null,
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'had_activity_score',
            'value' => $encodedScore,
            'value_multiplier' => $scoreMultiplier,
            'value_unit' => $options['value_unit'] ?? 'score',
            'event_metadata' => [
                'day' => $day,
                'kind' => 'activity',
            ],
            'blocks' => $blocks,
            'integration_id' => $this->integration->id,
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

    /**
     * Create events safely with race condition protection
     */
    private function createEventsSafely(array $eventData): void
    {
        foreach ($eventData as $data) {
            // Use updateOrCreate to prevent race conditions
            $event = Event::updateOrCreate(
                [
                    'integration_id' => $this->integration->id,
                    'source_id' => $data['source_id'],
                ],
                [
                    'time' => $data['time'],
                    'actor_id' => $this->createOrUpdateObject($data['actor'])->id,
                    'service' => $this->serviceName,
                    'domain' => $data['domain'],
                    'action' => $data['action'],
                    'value' => $data['value'] ?? null,
                    'value_multiplier' => $data['value_multiplier'] ?? 1,
                    'value_unit' => $data['value_unit'] ?? null,
                    'event_metadata' => $data['event_metadata'] ?? [],
                    'target_id' => $this->createOrUpdateObject($data['target'])->id,
                ]
            );

            // Create blocks if any
            if (isset($data['blocks'])) {
                foreach ($data['blocks'] as $blockData) {
                    $event->blocks()->create([
                        'time' => $blockData['time'] ?? $event->time,
                        'block_type' => $blockData['block_type'] ?? '',
                        'title' => $blockData['title'],
                        'metadata' => $blockData['metadata'] ?? [],
                        'url' => $blockData['url'] ?? null,
                        'media_url' => $blockData['media_url'] ?? null,
                        'value' => $blockData['value'] ?? null,
                        'value_multiplier' => $blockData['value_multiplier'] ?? 1,
                        'value_unit' => $blockData['value_unit'] ?? null,
                        'embeddings' => $blockData['embeddings'] ?? null,
                    ]);
                }
            }

            Log::info('Oura: Created activity event safely', [
                'integration_id' => $this->integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }
}
