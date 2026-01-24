<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class OuraCardiovascularAgeData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'cardiovascular_age';
    }

    protected function process(): void
    {
        $items = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($items)) {
            return;
        }

        Log::info('OuraCardiovascularAgeData: Processing cardiovascular age data', [
            'integration_id' => $this->integration->id,
            'item_count' => count($items),
        ]);

        foreach ($items as $item) {
            $this->createCardiovascularAgeEvent($item, $plugin);
        }

        Log::info('OuraCardiovascularAgeData: Completed processing cardiovascular age data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function createCardiovascularAgeEvent(array $item, OuraPlugin $plugin): void
    {
        $day = $item['day'] ?? null;
        if (! $day) {
            return;
        }

        $vascularAge = Arr::get($item, 'vascular_age');
        if ($vascularAge === null) {
            return;
        }

        $sourceId = "oura_cardiovascular_age_{$this->integration->id}_{$day}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $this->integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);
        $target = $plugin->getStaticMetricObject(
            $this->integration,
            'cardiovascular_age',
            'Cardiovascular Age',
            'Estimated cardiovascular age measurement'
        );

        [$encodedAge, $ageMultiplier] = $plugin->encodeNumericValue((float) $vascularAge);

        Event::create([
            'source_id' => $sourceId,
            'time' => $day.' 00:00:00',
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_cardiovascular_age',
            'value' => $encodedAge,
            'value_multiplier' => $ageMultiplier,
            'value_unit' => 'years',
            'event_metadata' => [
                'day' => $day,
            ],
            'target_id' => $target->id,
        ]);
    }
}
