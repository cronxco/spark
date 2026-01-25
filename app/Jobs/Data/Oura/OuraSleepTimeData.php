<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Oura\Traits\HasOuraBlocks;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class OuraSleepTimeData extends BaseProcessingJob
{
    use HasOuraBlocks;

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep_time';
    }

    protected function process(): void
    {
        $items = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($items)) {
            return;
        }

        Log::info('OuraSleepTimeData: Processing sleep time data', [
            'integration_id' => $this->integration->id,
            'item_count' => count($items),
        ]);

        foreach ($items as $item) {
            $this->createSleepTimeEvent($item, $plugin);
        }

        Log::info('OuraSleepTimeData: Completed processing sleep time data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function createSleepTimeEvent(array $item, OuraPlugin $plugin): void
    {
        $day = $item['day'] ?? null;
        $id = $item['id'] ?? null;

        if (! $day || ! $id) {
            return;
        }

        $sourceId = "oura_sleep_time_{$this->integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $this->integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);

        $recommendation = Arr::get($item, 'recommendation', 'Sleep timing recommendation');
        $status = Arr::get($item, 'status', 'unknown');
        $optimalBedtime = Arr::get($item, 'optimal_bedtime');

        $target = $plugin->getStaticMetricObject(
            $this->integration,
            'sleep_recommendation',
            'Sleep Recommendation',
            'Sleep timing recommendation'
        );

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_recommendation',
            'value' => null, // Informational event
            'value_multiplier' => 1,
            'value_unit' => null,
            'event_metadata' => [
                'day' => $day,
                'status' => $status,
                'recommendation_id' => $id,
            ],
            'target_id' => $target->id,
        ]);

        // Add recommendation details as blocks
        $recommendationFields = [];
        if ($recommendation) {
            $recommendationFields['recommendation'] = 'Recommendation';
        }
        if ($status) {
            $recommendationFields['status'] = 'Status';
        }
        if ($optimalBedtime) {
            $recommendationFields['optimal_bedtime'] = 'Optimal Bedtime';
        }

        if (! empty($recommendationFields)) {
            $this->createRecommendationBlocks($event, $item, $recommendationFields, $plugin);
        }
    }
}
