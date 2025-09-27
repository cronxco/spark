<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

class OuraStressData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'stress';
    }

    protected function process(): void
    {
        $stressItems = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($stressItems)) {
            return;
        }

        Log::info('OuraStressData: Processing stress data', [
            'integration_id' => $this->integration->id,
            'stress_count' => count($stressItems),
        ]);

        foreach ($stressItems as $item) {
            $this->processStressItem($plugin, $item);
        }

        Log::info('OuraStressData: Completed processing stress data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function processStressItem(OuraPlugin $plugin, array $item): void
    {
        $day = $item['day'] ?? null;
        $daySummary = $item['day_summary'] ?? null;

        if (! $day || ! $daySummary) {
            Log::debug('OuraStressData: Skipping item missing day or day_summary', [
                'item' => $item,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Map the day_summary to a numeric value for storage
        $mappedValue = $plugin->mapValueForStorage('stress_day_summary', $daySummary);

        if ($mappedValue === null) {
            Log::debug('OuraStressData: No mapping found for day_summary', [
                'day_summary' => $daySummary,
                'item' => $item,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        $sourceId = "oura_stress_{$this->integration->id}_{$day}";

        // Check if event already exists
        $existingEvent = Event::where('source_id', $sourceId)
            ->where('integration_id', $this->integration->id)
            ->first();

        if ($existingEvent) {
            Log::debug('OuraStressData: Event already exists, skipping', [
                'source_id' => $sourceId,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Create actor and target
        $actor = $plugin->ensureUserProfile($this->integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'metric',
            'type' => 'oura_daily_stress',
            'title' => 'Daily Stress',
        ], [
            'time' => $day . ' 00:00:00',
            'content' => 'Daily stress level assessment with timing data',
            'metadata' => $item,
        ]);

        // Encode the mapped value
        [$encodedValue, $multiplier] = $plugin->encodeNumericValue($mappedValue);

        // Create the main stress event with mapped day_summary value
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_stress_score',
            'value' => $encodedValue,
            'value_multiplier' => $multiplier,
            'value_unit' => 'stress_level',
            'event_metadata' => [
                'day' => $day,
                'original_day_summary' => $daySummary,
                'mapped_value' => $mappedValue,
            ],
            'target_id' => $target->id,
        ]);

        // Add stress_high block (in seconds)
        if (isset($item['stress_high'])) {
            $event->blocks()->create([
                'block_type' => 'biometrics',
                'time' => $event->time,
                'integration_id' => $this->integration->id,
                'title' => 'Stress High Duration',
                'metadata' => [
                    'type' => 'stress_duration',
                    'stress_level' => 'high',
                    'context' => 'daily_stress',
                ],
                'value' => (int) $item['stress_high'],
                'value_multiplier' => 1,
                'value_unit' => 'seconds',
            ]);
        }

        // Add recovery_high block (in seconds)
        if (isset($item['recovery_high'])) {
            $event->blocks()->create([
                'block_type' => 'biometrics',
                'time' => $event->time,
                'integration_id' => $this->integration->id,
                'title' => 'Recovery High Duration',
                'metadata' => [
                    'type' => 'recovery_duration',
                    'recovery_level' => 'high',
                    'context' => 'daily_stress',
                ],
                'value' => (int) $item['recovery_high'],
                'value_multiplier' => 1,
                'value_unit' => 'seconds',
            ]);
        }

        Log::debug('OuraStressData: Successfully processed stress item', [
            'day' => $day,
            'day_summary' => $daySummary,
            'mapped_value' => $mappedValue,
            'stress_high' => $item['stress_high'] ?? null,
            'recovery_high' => $item['recovery_high'] ?? null,
            'integration_id' => $this->integration->id,
        ]);
    }
}
