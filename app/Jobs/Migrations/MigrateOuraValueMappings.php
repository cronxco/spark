<?php

namespace App\Jobs\Migrations;

use App\Integrations\Oura\OuraPlugin;
use App\Models\Event;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class MigrateOuraValueMappings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [60, 300, 600];

    protected ?Integration $integration;

    public function __construct(?Integration $integration = null)
    {
        $this->integration = $integration;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        $plugin = new OuraPlugin;
        $mappings = OuraPlugin::getValueMappings();

        Log::info('Starting Oura value mapping migration', [
            'integration_id' => $this->integration?->id,
            'mappings_count' => count($mappings),
        ]);

        $query = Event::where('service', 'oura')
            ->whereIn('action', ['had_stress_level', 'had_resilience_level']);

        if ($this->integration) {
            $query->where('integration_id', $this->integration->id);
        }

        $events = $query->get();
        $processed = 0;
        $errors = 0;

        foreach ($events as $event) {
            try {
                $this->migrateEvent($event, $plugin, $mappings);
                $processed++;
            } catch (Throwable $e) {
                $errors++;
                Log::error('Failed to migrate Oura event', [
                    'event_id' => $event->id,
                    'action' => $event->action,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Oura value mapping migration completed', [
            'integration_id' => $this->integration?->id,
            'processed' => $processed,
            'errors' => $errors,
            'total_events' => $events->count(),
        ]);
    }

    protected function migrateEvent(Event $event, OuraPlugin $plugin, array $mappings): void
    {
        $metadata = $event->event_metadata ?? [];
        $mappingKey = $metadata['mapping_key'] ?? null;
        $originalValue = $metadata['original_value'] ?? null;

        if (! $mappingKey || ! isset($mappings[$mappingKey])) {
            Log::warning('Event missing mapping key or mapping not found', [
                'event_id' => $event->id,
                'mapping_key' => $mappingKey,
                'available_mappings' => array_keys($mappings),
            ]);

            return;
        }

        $mapping = $mappings[$mappingKey];
        $fieldName = $mapping['field_name'];

        // Get the original data from the target object metadata
        $target = $event->target;
        if (! $target || ! isset($target->metadata[$fieldName])) {
            Log::warning('Target object missing or field not found', [
                'event_id' => $event->id,
                'target_id' => $target?->id,
                'field_name' => $fieldName,
            ]);

            return;
        }

        $rawValue = $target->metadata[$fieldName];
        $mappedValue = $plugin->mapValueForStorage($mappingKey, $rawValue);

        if ($mappedValue === null) {
            Log::warning('Failed to map value', [
                'event_id' => $event->id,
                'mapping_key' => $mappingKey,
                'raw_value' => $rawValue,
            ]);

            return;
        }

        // Update the event with the mapped value
        $event->update([
            'value' => $mappedValue,
            'value_multiplier' => 1, // Mapped values are stored as integers
            'event_metadata' => array_merge($metadata, [
                'migrated' => true,
                'migrated_at' => now()->toISOString(),
                'original_value' => $originalValue,
                'mapped_value' => $mappedValue,
            ]),
        ]);

        Log::debug('Event migrated successfully', [
            'event_id' => $event->id,
            'mapping_key' => $mappingKey,
            'raw_value' => $rawValue,
            'mapped_value' => $mappedValue,
        ]);
    }
}
