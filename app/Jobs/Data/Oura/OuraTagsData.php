<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OuraTagsData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'tags';
    }

    protected function process(): void
    {
        $tags = $this->rawData;

        if (empty($tags)) {
            return;
        }

        $events = [];
        foreach ($tags as $item) {
            $eventData = $this->createTagEvent($item);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            $this->createEventsSafely($events);
        }
    }

    private function createTagEvent(array $item): ?array
    {
        $timestamp = Arr::get($item, 'timestamp') ?? Arr::get($item, 'time') ?? now()->toIso8601String();
        $day = Str::substr($timestamp, 0, 10);
        $sourceId = "oura_tag_{$this->integration->id}_" . md5(json_encode($item));

        $actor = [
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => $timestamp,
        ];

        $target = [
            'concept' => 'tag',
            'type' => 'oura_tag',
            'title' => 'Oura Tag',
            'time' => $timestamp,
            'metadata' => $item,
        ];

        $label = Arr::get($item, 'tag') ?? Arr::get($item, 'label', 'Tag');

        return [
            'source_id' => $sourceId,
            'time' => $timestamp,
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'had_oura_tag',
            'value' => null,
            'value_multiplier' => 1,
            'value_unit' => null,
            'event_metadata' => [
                'day' => $day,
                'label' => $label,
            ],
            'blocks' => [
                [
                    'time' => $timestamp,
                    'title' => 'Tag',
                    'metadata' => ['text' => (string) $label],
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                ],
            ],
            'integration_id' => $this->integration->id,
        ];
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

            Log::info('Oura: Created tag event safely', [
                'integration_id' => $this->integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }
}
