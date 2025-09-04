<?php

namespace App\Jobs\Data\AppleHealth;

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
        $events = [];

        $name = (string) (Arr::get($metricEntry, 'name') ?? 'unknown_metric');
        $unit = $metricEntry['units'] ?? null;
        $dataPoints = is_array($metricEntry['data'] ?? null) ? $metricEntry['data'] : [];

        foreach ($dataPoints as $point) {
            if (is_array($point)) {
                $eventData = $this->mapMetricPointToEvent($name, $unit, $point);
                if ($eventData) {
                    $events[] = $eventData;
                }
            }
        }

        if (! empty($events)) {
            $this->createEventsSafely($events);
        }
    }

    private function mapMetricPointToEvent(string $name, ?string $unit, array $point): ?array
    {
        $date = (string) (Arr::get($point, 'date') ?? now()->toDateString());
        $sourceId = 'apple_metric_' . $name . '_' . str_replace([' ', ':', '+'], ['_', '', ''], $date);

        // Prefer Avg if present for series like heart_rate
        $value = Arr::get($point, 'Avg');
        if ($value === null) {
            $value = Arr::get($point, 'qty');
        }
        [$enc, $mult] = $this->encodeNumericValue($value);

        $actor = [
            'concept' => 'user',
            'type' => 'apple_health_user',
            'title' => 'Apple Health',
            'time' => $date,
        ];

        $target = [
            'concept' => 'metric',
            'type' => 'apple_metric',
            'title' => $name,
            'time' => $date,
        ];

        $blocks = [];
        // If there are Min/Max/Avg, capture them as blocks
        $statMap = [
            'Min' => 'Minimum',
            'Avg' => 'Average',
            'Max' => 'Maximum',
        ];
        foreach ($statMap as $key => $label) {
            if (array_key_exists($key, $point)) {
                [$bVal, $bMult] = $this->encodeNumericValue($point[$key]);
                $blocks[] = [
                    'time' => $date,
                    'title' => $label,
                    'metadata' => ['text' => $label . ' value for ' . $name],
                    'value' => $bVal,
                    'value_multiplier' => $bMult,
                    'value_unit' => $unit,
                ];
            }
        }
        if (array_key_exists('source', $point)) {
            $blocks[] = [
                'time' => $date,
                'title' => 'Source',
                'metadata' => ['text' => (string) $point['source']],
            ];
        }

        return [
            'source_id' => $sourceId,
            'time' => $date,
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'measurement',
            'value' => $enc,
            'value_multiplier' => $mult,
            'value_unit' => $unit,
            'event_metadata' => [
                'metric' => $name,
                'raw' => $point,
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * Encode a numeric value into an integer with a multiplier to retain precision.
     * Returns [encodedInt|null, multiplier|null].
     */
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

            Log::info('Apple Health: Created metric event safely', [
                'integration_id' => $this->integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }
}
