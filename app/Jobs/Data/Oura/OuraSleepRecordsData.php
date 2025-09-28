<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Oura\Traits\HasOuraBlocks;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OuraSleepRecordsData extends BaseProcessingJob
{
    use HasOuraBlocks;

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep_records';
    }

    protected function process(): void
    {
        $sleepRecords = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($sleepRecords)) {
            return;
        }

        $events = [];
        foreach ($sleepRecords as $item) {
            $eventData = $this->createSleepRecordEvent($item, $plugin);
            if ($eventData) {
                $events[] = $eventData;
            }
        }

        if (! empty($events)) {
            $this->createEventsSafely($events);
        }
    }

    /**
     * Convert character string to array for display
     */
    private function stringToCharArray(string $input): array
    {
        return str_split($input);
    }

    private function createSleepRecordEvent(array $item, OuraPlugin $plugin): ?array
    {
        $start = Arr::get($item, 'bedtime_start');
        $end = Arr::get($item, 'bedtime_end');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $id = Arr::get($item, 'id') ?? md5(json_encode([$day, Arr::get($item, 'duration', 0), Arr::get($item, 'total', 0)]));
        $sourceId = "oura_sleep_record_{$this->integration->id}_{$id}";

        $actor = [
            'concept' => 'user',
            'type' => 'oura_user',
            'title' => 'Oura User',
            'time' => $start ?? ($day . ' 00:00:00'),
        ];

        $target = [
            'concept' => 'sleep',
            'type' => 'oura_sleep_record',
            'title' => 'Sleep Record',
            'time' => $start ?? ($day . ' 00:00:00'),
            'metadata' => $item,
        ];

        // Get the total_sleep_duration as the main value (instead of duration)
        $totalSleepDuration = (int) Arr::get($item, 'total_sleep_duration', 0);
        $efficiency = Arr::get($item, 'efficiency');

        // Process sleep stages
        $sleepMetrics = [
            'total_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'Total Sleep Duration',
                'type' => 'sleep_metric',
                'category' => 'duration',
            ],
            'deep_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'Deep Sleep Duration',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
            'light_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'Light Sleep Duration',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
            'rem_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'REM Sleep Duration',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
            'awake_time' => [
                'unit' => 'seconds',
                'title' => 'Awake Time',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
            'latency' => [
                'unit' => 'seconds',
                'title' => 'Sleep Latency',
                'type' => 'sleep_metric',
                'category' => 'onset',
            ],
            'restless_periods' => [
                'unit' => 'count',
                'title' => 'Restless Periods',
                'type' => 'sleep_metric',
                'category' => 'quality',
            ],
            'average_breath' => [
                'unit' => 'bpm',
                'title' => 'Average Breath',
                'type' => 'sleep_metric',
                'category' => 'breathing',
            ],
        ];

        // Create the heart rate blocks from heart_rate data
        $heartRateData = [];
        if (isset($item['lowest_heart_rate'])) {
            $heartRateData['min'] = $item['lowest_heart_rate'];
        }
        if (isset($item['average_heart_rate'])) {
            $heartRateData['avg'] = $item['average_heart_rate'];
        }
        if (isset($item['heart_rate']) && isset($item['heart_rate']['items']) && is_array($item['heart_rate']['items'])) {
            $heartRateData['items'] = $item['heart_rate']['items'];
        }

        // Create the HRV blocks
        $hrvData = [];
        if (isset($item['average_hrv'])) {
            $hrvData['avg'] = $item['average_hrv'];
        }
        if (isset($item['hrv']) && isset($item['hrv']['items']) && is_array($item['hrv']['items'])) {
            $hrvData['items'] = $item['hrv']['items'];
        }

        // Convert movement_30_sec and sleep_phase_5_min to arrays if present
        $movement30Sec = Arr::get($item, 'movement_30_sec');
        $sleepPhase5Min = Arr::get($item, 'sleep_phase_5_min');

        // Build metadata with the sleep phases and movement arrays
        $eventMetadata = [
            'end' => $end,
            'efficiency' => $efficiency,
        ];

        if ($movement30Sec) {
            $eventMetadata['movement_30_sec_array'] = $this->stringToCharArray($movement30Sec);
        }

        if ($sleepPhase5Min) {
            $eventMetadata['sleep_phase_5_min_array'] = $this->stringToCharArray($sleepPhase5Min);
        }

        // Add timing fields
        $timingFields = [
            'bedtime_start' => 'Bedtime Start',
            'bedtime_end' => 'Bedtime End',
        ];

        // Create blocks separately instead of directly in the return
        $blocks = [];

        // Sleep stage blocks
        foreach ($sleepMetrics as $field => $config) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($value);
                $blocks[] = [
                    'time' => $start ?? ($day . ' 00:00:00'),
                    'block_type' => 'sleep_stages',
                    'title' => $config['title'],
                    'metadata' => [
                        'type' => $config['type'],
                        'field' => $field,
                        'category' => $config['category'],
                    ],
                    'value' => $encodedValue,
                    'value_multiplier' => $valueMultiplier,
                    'value_unit' => $config['unit'],
                ];
            }
        }

        // Heart rate block with the lowest heart rate as the main value
        if (! empty($heartRateData)) {
            [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($heartRateData['min'] ?? null);
            $blocks[] = [
                'time' => $start ?? ($day . ' 00:00:00'),
                'block_type' => 'heart_rate',
                'title' => 'Heart Rate',
                'metadata' => [
                    'type' => 'minimum',
                    'context' => 'sleep',
                    'average_heart_rate' => $heartRateData['avg'] ?? null,
                    'items' => $heartRateData['items'] ?? null,
                ],
                'value' => $encodedValue,
                'value_multiplier' => $valueMultiplier,
                'value_unit' => 'bpm',
            ];
        }

        // HRV block with average HRV as the main value
        if (! empty($hrvData)) {
            [$encodedValue, $valueMultiplier] = $plugin->encodeNumericValue($hrvData['avg'] ?? null);
            $blocks[] = [
                'time' => $start ?? ($day . ' 00:00:00'),
                'block_type' => 'heart_rate',
                'title' => 'Heart Rate Variability',
                'metadata' => [
                    'type' => 'hrv',
                    'context' => 'sleep',
                    'items' => $hrvData['items'] ?? null,
                ],
                'value' => $encodedValue,
                'value_multiplier' => $valueMultiplier,
                'value_unit' => 'rmssd',
            ];
        }

        // Add timing blocks (bedtime_start, bedtime_end)
        foreach ($timingFields as $field => $title) {
            $value = $item[$field] ?? null;
            if ($value) {
                $blocks[] = [
                    'time' => $start ?? ($day . ' 00:00:00'),
                    'block_type' => 'sleep_stages',
                    'title' => $title,
                    'metadata' => [
                        'type' => 'timing',
                        'field' => $field,
                        'value' => $value,
                    ],
                ];
            }
        }

        return [
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'actor' => $actor,
            'target' => $target,
            'domain' => 'health',
            'action' => 'slept_for',
            'value' => $totalSleepDuration, // Use total_sleep_duration as the main event value
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'event_metadata' => $eventMetadata,
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

            Log::info('Oura: Created sleep record event safely', [
                'integration_id' => $this->integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }
}
