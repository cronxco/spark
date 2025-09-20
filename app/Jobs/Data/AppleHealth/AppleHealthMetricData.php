<?php

namespace App\Jobs\Data\AppleHealth;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class AppleHealthMetricData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'apple_health';
    }

    protected function getJobType(): string
    {
        return 'metrics';
    }

    protected function process(): void
    {
        $metricEntry = $this->rawData;
        $plugin = new AppleHealthPlugin;
        $events = [];

        $name = (string) (Arr::get($metricEntry, 'name') ?? 'unknown_metric');
        $unit = $metricEntry['units'] ?? null;
        $dataPoints = is_array($metricEntry['data'] ?? null) ? $metricEntry['data'] : [];

        Log::info('AppleHealthMetricData: Processing metrics', [
            'integration_id' => $this->integration->id,
            'metric_name' => $name,
            'data_points' => count($dataPoints),
        ]);

        foreach ($dataPoints as $point) {
            if (is_array($point)) {
                $eventData = $plugin->mapMetricPointToEvent($name, $unit, $point, $this->integration);
                if ($eventData) {
                    $events[] = $eventData;
                }
            }
        }

        if (! empty($events)) {
            $this->createEventsSafely($events);
        }

        Log::info('AppleHealthMetricData: Completed processing metrics', [
            'integration_id' => $this->integration->id,
            'events_created' => count($events),
        ]);
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

            Log::info('Apple Health: Created metric event safely', [
                'integration_id' => $this->integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }
}
