<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Oura\Traits\HasOuraBlocks;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class OuraEnhancedTagData extends BaseProcessingJob
{
    use HasOuraBlocks;

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'enhanced_tag';
    }

    protected function process(): void
    {
        $items = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($items)) {
            return;
        }

        Log::info('OuraEnhancedTagData: Processing enhanced tag data', [
            'integration_id' => $this->integration->id,
            'item_count' => count($items),
        ]);

        foreach ($items as $item) {
            $this->createEnhancedTagEvent($item, $plugin);
        }

        Log::info('OuraEnhancedTagData: Completed processing enhanced tag data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function createEnhancedTagEvent(array $item, OuraPlugin $plugin): void
    {
        $id = $item['id'] ?? null;
        $startDay = $item['start_day'] ?? null;
        $startTime = $item['start_time'] ?? null;
        $endDay = $item['end_day'] ?? null;
        $endTime = $item['end_time'] ?? null;

        if (! $id || ! $startDay) {
            return;
        }

        // Calculate duration if we have end time
        $duration = null;
        if ($endDay && $endTime) {
            try {
                $startDateTime = Carbon::parse($startDay . ' ' . ($startTime ?? '00:00:00'));
                $endDateTime = Carbon::parse($endDay . ' ' . $endTime);
                $duration = $endDateTime->diffInSeconds($startDateTime);
            } catch (Exception $e) {
                Log::warning('Failed to calculate enhanced tag duration', [
                    'item_id' => $id,
                    'start' => $startDay . ' ' . ($startTime ?? '00:00:00'),
                    'end' => $endDay . ' ' . $endTime,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $sourceId = "oura_enhanced_tag_{$this->integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $this->integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);

        $tagType = Arr::get($item, 'tag_type_code', 'unknown');
        $customName = Arr::get($item, 'custom_name');
        $comment = Arr::get($item, 'comment');

        // Create tag object only once per tag ID
        $target = EventObject::firstOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'tag',
            'type' => 'enhanced_tag',
            'title' => $customName ?: "Enhanced Tag ({$tagType})",
        ], [
            'time' => $startDay . ' ' . ($startTime ?? '00:00:00'),
            'content' => $comment ?: 'Enhanced tag with detailed metadata',
            'metadata' => [],
        ]);

        [$encodedDuration, $durationMultiplier] = $plugin->encodeNumericValue($duration);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $startDay . ' ' . ($startTime ?? '00:00:00'),
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_enhanced_tag',
            'value' => $encodedDuration,
            'value_multiplier' => $durationMultiplier,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'start_day' => $startDay,
                'end_day' => $endDay,
                'tag_type' => $tagType,
                'tag_id' => $id,
            ],
            'target_id' => $target->id,
        ]);

        // Add metadata blocks for detailed tag information
        $tagFields = [];
        if ($tagType) {
            $tagFields['tag_type'] = 'Tag Type';
        }
        if ($comment) {
            $tagFields['comment'] = 'Comment';
        }
        if ($endDay && $endTime) {
            $tagFields['end_time'] = 'End Time';
            // Prepare combined end time value
            $item['end_time'] = $endDay . ' ' . $endTime;
        }

        // Prepare tag type value for the trait method
        if ($tagType) {
            $item['tag_type'] = $tagType;
        }

        if (! empty($tagFields)) {
            $this->createTagInfoBlocks($event, $item, $tagFields, $plugin);
        }
    }
}
