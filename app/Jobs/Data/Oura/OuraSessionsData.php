<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Arr;
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
            $this->createEvents($events);
        }
    }

    private function createSessionEvent(array $item): ?array
    {
        $start = Arr::get($item, 'start_datetime') ?? Arr::get($item, 'timestamp');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $sourceId = "oura_session_{$this->integration->id}_" . (Arr::get($item, 'id') ?? ($day . '_' . md5(json_encode($item))));

        if ($this->eventExists($sourceId)) {
            return null;
        }

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
        ];
    }
}
