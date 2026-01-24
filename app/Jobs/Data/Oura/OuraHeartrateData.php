<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Collection;
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
        $plugin = new OuraPlugin;

        if (empty($heartratePoints)) {
            return;
        }

        // Aggregate to one event per day with summary blocks
        $byDay = collect($heartratePoints)->groupBy(fn ($p) => Str::substr($p['timestamp'] ?? $p['start_datetime'] ?? '', 0, 10));

        $events = [];
        foreach ($byDay as $day => $points) {
            $eventData = $this->createHeartrateEvent($day, $points, $plugin);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            foreach ($events as $eventData) {
                // Create the event first
                $event = Event::updateOrCreate(
                    [
                        'integration_id' => $this->integration->id,
                        'source_id' => $eventData['source_id'],
                    ],
                    [
                        'time' => $eventData['time'],
                        'actor_id' => $plugin->createOrUpdateObject($this->integration, $eventData['actor'])->id,
                        'service' => 'oura',
                        'domain' => $eventData['domain'],
                        'action' => $eventData['action'],
                        'value' => $eventData['value'] ?? null,
                        'value_multiplier' => $eventData['value_multiplier'] ?? 1,
                        'value_unit' => $eventData['value_unit'] ?? null,
                        'event_metadata' => $eventData['event_metadata'] ?? [],
                        'target_id' => $plugin->createOrUpdateObject($this->integration, $eventData['target'])->id,
                    ]
                );

                // Create blocks using the new unique method
                if (isset($eventData['blocks_data'])) {
                    foreach ($eventData['blocks_data'] as $blockData) {
                        $event->createBlock($blockData);
                    }
                }
            }
        }
    }

    private function createHeartrateEvent(string $day, Collection $points, OuraPlugin $plugin): ?array
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

        [$encodedAvg, $avgMultiplier] = $plugin->encodeNumericValue($avg);

        // Prepare blocks data to be created separately
        $blocksData = [];

        [$encMin, $minMult] = $plugin->encodeNumericValue($min);
        $blocksData[] = [
            'time' => $day.' 00:00:00',
            'title' => 'Min Heart Rate',
            'block_type' => 'heart_rate',
            'metadata' => ['type' => 'minimum', 'context' => 'daily_series'],
            'value' => $encMin,
            'value_multiplier' => $minMult,
            'value_unit' => 'bpm',
        ];

        [$encMax, $maxMult] = $plugin->encodeNumericValue($max);
        $blocksData[] = [
            'time' => $day.' 00:00:00',
            'title' => 'Max Heart Rate',
            'block_type' => 'heart_rate',
            'metadata' => ['type' => 'maximum', 'context' => 'daily_series'],
            'value' => $encMax,
            'value_multiplier' => $maxMult,
            'value_unit' => 'bpm',
        ];

        $blocksData[] = [
            'time' => $day.' 00:00:00',
            'title' => 'Data Points',
            'block_type' => 'heart_rate',
            'metadata' => ['type' => 'count', 'context' => 'daily_series'],
            'value' => (int) $points->count(),
            'value_multiplier' => 1,
            'value_unit' => 'count',
        ];

        return [
            'source_id' => $sourceId,
            'time' => $day.' 00:00:00',
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
            'blocks_data' => $blocksData,
            'integration_id' => $this->integration->id,
        ];
    }
}
