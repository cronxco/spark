<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OuraSessionsData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sessions';
    }

    protected function process(): void
    {
        $sessions = $this->rawData;

        if (empty($sessions)) {
            return;
        }

        $events = [];
        foreach ($sessions as $item) {
            $eventData = $this->createSessionEvent($item);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            $this->createEventsSafely($events);
        }
    }

    private function createSessionEvent(array $item): ?array
    {
        $start = Arr::get($item, 'start_datetime') ?? Arr::get($item, 'timestamp');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $sourceId = "oura_session_{$this->integration->id}_" . (Arr::get($item, 'id') ?? ($day . '_' . md5(json_encode($item))));

        $actor = [
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => $start ?? ($day . ' 00:00:00'),
        ];

        $target = [
            'concept' => 'mindfulness_session',
            'type' => Arr::get($item, 'type', 'session'),
            'title' => Str::title((string) Arr::get($item, 'type', 'Session')),
            'time' => $start ?? ($day . ' 00:00:00'),
            'metadata' => $item,
        ];

        $durationSec = (int) Arr::get($item, 'duration', 0);

        $blocks = [];
        $state = Arr::get($item, 'mood', Arr::get($item, 'state'));
        if ($state) {
            $blocks[] = [
                'time' => $start ?? ($day . ' 00:00:00'),
                'title' => 'State',
                'metadata' => ['text' => (string) $state],
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'had_mindfulness_session',
            'value' => $durationSec,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'day' => $day,
            ],
            'blocks' => $blocks,
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

            Log::info('Oura: Created session event safely', [
                'integration_id' => $this->integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }
}
