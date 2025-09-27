<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

class OuraResilienceData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'resilience';
    }

    protected function process(): void
    {
        $resilienceItems = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($resilienceItems)) {
            return;
        }

        Log::info('OuraResilienceData: Processing resilience data', [
            'integration_id' => $this->integration->id,
            'resilience_count' => count($resilienceItems),
        ]);

        foreach ($resilienceItems as $item) {
            $this->processResilienceItem($plugin, $item);
        }

        Log::info('OuraResilienceData: Completed processing resilience data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function processResilienceItem(OuraPlugin $plugin, array $item): void
    {
        $day = $item['day'] ?? null;
        $level = $item['level'] ?? null;

        if (! $day || ! $level) {
            Log::debug('OuraResilienceData: Skipping item missing day or level', [
                'item' => $item,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Map the level to a numeric value for storage
        $mappedValue = $plugin->mapValueForStorage('resilience_level', $level);

        if ($mappedValue === null) {
            Log::debug('OuraResilienceData: No mapping found for level', [
                'level' => $level,
                'item' => $item,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        $sourceId = "oura_resilience_{$this->integration->id}_{$day}";

        // Check if event already exists
        $existingEvent = Event::where('source_id', $sourceId)
            ->where('integration_id', $this->integration->id)
            ->first();

        if ($existingEvent) {
            Log::debug('OuraResilienceData: Event already exists, skipping', [
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
            'type' => 'oura_daily_resilience',
            'title' => 'Daily Resilience',
        ], [
            'time' => $day . ' 00:00:00',
            'content' => 'Daily resilience level assessment',
            'metadata' => $item,
        ]);

        // Encode the mapped value
        [$encodedValue, $multiplier] = $plugin->encodeNumericValue($mappedValue);

        // Create the main resilience event with mapped level value
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_resilience_score',
            'value' => $encodedValue,
            'value_multiplier' => $multiplier,
            'value_unit' => 'resilience_level',
            'event_metadata' => [
                'day' => $day,
                'original_level' => $level,
                'mapped_value' => $mappedValue,
            ],
            'target_id' => $target->id,
        ]);

        // Add contributor blocks (without metadata)
        $contributors = $item['contributors'] ?? [];
        foreach ($contributors as $name => $value) {
            if (is_numeric($value)) {
                [$encodedContrib, $contribMultiplier] = $plugin->encodeNumericValue((float) $value);
                $event->blocks()->create([
                    'block_type' => 'contributors',
                    'time' => $event->time,
                    'integration_id' => $this->integration->id,
                    'title' => str_replace('_', ' ', ucwords($name)),
                    'value' => $encodedContrib,
                    'value_multiplier' => $contribMultiplier,
                    'value_unit' => 'percent',
                ]);
            }
        }

        Log::debug('OuraResilienceData: Successfully processed resilience item', [
            'day' => $day,
            'level' => $level,
            'mapped_value' => $mappedValue,
            'contributors_count' => count($contributors),
            'integration_id' => $this->integration->id,
        ]);
    }
}
