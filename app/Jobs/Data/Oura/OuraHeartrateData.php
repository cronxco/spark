<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OuraHeartrateData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'heartrate';
    }

    protected function process(): void
    {
        $heartratePoints = $this->rawData;

        if (empty($heartratePoints)) {
            return;
        }

        // Aggregate to one event per day with summary blocks
        $byDay = collect($heartratePoints)->groupBy(fn ($p) => Str::substr($p['timestamp'] ?? $p['start_datetime'] ?? '', 0, 10));

        $events = [];
        foreach ($byDay as $day => $points) {
            $eventData = $this->createHeartrateEvent($day, $points);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            $this->createEventsSafely($events);
        }
    }

    private function createHeartrateEvent(string $day, Collection $points): ?array
    {
        $sourceId = "oura_heartrate_{$this->integration->id}_{$day}";

        $actor = [
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => now(),
        ];

        $target = [
            'concept' => 'metric',
            'type' => 'heartrate_series',
            'title' => 'Heart Rate',
            'time' => now(),
            'metadata' => [
                'interval' => 'irregular',
            ],
        ];

        $min = (int) $points->min('bpm');
        $max = (int) $points->max('bpm');
        $avg = (float) $points->avg('bpm');

        [$encodedAvg, $avgMultiplier] = $this->encodeNumericValue($avg);

        $blocks = [];
        [$encMin, $minMult] = $this->encodeNumericValue($min);
        $blocks[] = [
            'time' => $day . ' 00:00:00',
            'title' => 'Min Heart Rate',
            'metadata' => [],
            'value' => $encMin,
            'value_multiplier' => $minMult,
            'value_unit' => 'bpm',
        ];

        [$encMax, $maxMult] = $this->encodeNumericValue($max);
        $blocks[] = [
            'time' => $day . ' 00:00:00',
            'title' => 'Max Heart Rate',
            'metadata' => [],
            'value' => $encMax,
            'value_multiplier' => $maxMult,
            'value_unit' => 'bpm',
        ];

        $blocks[] = [
            'time' => $day . ' 00:00:00',
            'title' => 'Data Points',
            'metadata' => ['text' => 'Count of heart rate points collected for the day'],
            'value' => (int) $points->count(),
            'value_multiplier' => 1,
            'value_unit' => 'count',
        ];

        return [
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'had_heart_rate',
            'value' => $encodedAvg,
            'value_multiplier' => $avgMultiplier,
            'value_unit' => 'bpm',
            'event_metadata' => [
                'day' => $day,
                'min_bpm' => $min,
                'max_bpm' => $max,
                'avg_bpm' => $avg,
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

            Log::info('Oura: Created heartrate event safely', [
                'integration_id' => $this->integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }
}
