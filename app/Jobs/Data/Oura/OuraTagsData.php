<?php

namespace App\Jobs\Data\Oura;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Arr;
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
            $this->createEvents($events);
        }
    }

    private function createTagEvent(array $item): ?array
    {
        $timestamp = Arr::get($item, 'timestamp') ?? Arr::get($item, 'time') ?? now()->toIso8601String();
        $day = Str::substr($timestamp, 0, 10);
        $sourceId = "oura_tag_{$this->integration->id}_" . md5(json_encode($item));

        if ($this->eventExists($sourceId)) {
            return null;
        }

        $actor = $this->createOrUpdateObject([
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => $timestamp,
        ]);

        $target = $this->createOrUpdateObject([
            'concept' => 'tag',
            'type' => 'oura_tag',
            'title' => 'Oura Tag',
            'time' => $timestamp,
            'metadata' => $item,
        ]);

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
        ];
    }
}
