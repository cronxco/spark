<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OuraVO2MaxData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'vo2_max';
    }

    protected function process(): void
    {
        $items = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($items)) {
            return;
        }

        Log::info('OuraVO2MaxData: Processing VO2 max data', [
            'integration_id' => $this->integration->id,
            'item_count' => count($items),
        ]);

        foreach ($items as $item) {
            $this->createVO2MaxEvent($item, $plugin);
        }

        Log::info('OuraVO2MaxData: Completed processing VO2 max data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function createVO2MaxEvent(array $item, OuraPlugin $plugin): void
    {
        $day = $item['day'] ?? null;
        $timestamp = $item['timestamp'] ?? null;
        $id = $item['id'] ?? null;

        if (! $day || ! $id) {
            return;
        }

        $vo2Max = Arr::get($item, 'vo2_max');
        if ($vo2Max === null) {
            return;
        }

        $sourceId = "oura_vo2_max_{$this->integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $this->integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'metric',
            'type' => 'vo2_max',
            'title' => 'VO2 Max',
        ], [
            'time' => $timestamp ? Str::substr($timestamp, 0, 19) : ($day . ' 00:00:00'),
            'content' => 'Maximum oxygen consumption measurement',
            'metadata' => $item,
        ]);

        [$encodedVO2, $vo2Multiplier] = $plugin->encodeNumericValue((float) $vo2Max);

        Event::create([
            'source_id' => $sourceId,
            'time' => $timestamp ? Str::substr($timestamp, 0, 19) : ($day . ' 00:00:00'),
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_vo2_max',
            'value' => $encodedVO2,
            'value_multiplier' => $vo2Multiplier,
            'value_unit' => 'ml/kg/min',
            'event_metadata' => [
                'day' => $day,
                'measurement_id' => $id,
            ],
            'target_id' => $target->id,
        ]);
    }
}
