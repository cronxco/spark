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

class OuraRestModePeriodData extends BaseProcessingJob
{
    use HasOuraBlocks;

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'rest_mode_period';
    }

    protected function process(): void
    {
        $items = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($items)) {
            return;
        }

        Log::info('OuraRestModePeriodData: Processing rest mode period data', [
            'integration_id' => $this->integration->id,
            'item_count' => count($items),
        ]);

        foreach ($items as $item) {
            $this->createRestModePeriodEvent($item, $plugin);
        }

        Log::info('OuraRestModePeriodData: Completed processing rest mode period data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function createRestModePeriodEvent(array $item, OuraPlugin $plugin): void
    {
        $id = $item['id'] ?? null;
        $startDay = $item['start_day'] ?? null;
        $startTime = $item['start_time'] ?? null;
        $endDay = $item['end_day'] ?? null;
        $endTime = $item['end_time'] ?? null;

        if (! $id || ! $startDay) {
            return;
        }

        // Calculate duration
        $duration = null;
        if ($endDay && $endTime) {
            try {
                $startDateTime = Carbon::parse($startDay . ' ' . ($startTime ?? '00:00:00'));
                $endDateTime = Carbon::parse($endDay . ' ' . $endTime);
                $duration = $endDateTime->diffInSeconds($startDateTime);
            } catch (Exception $e) {
                Log::warning('Failed to calculate rest mode period duration', [
                    'item_id' => $id,
                    'start' => $startDay . ' ' . ($startTime ?? '00:00:00'),
                    'end' => $endDay . ' ' . $endTime,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $sourceId = "oura_rest_mode_period_{$this->integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $this->integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);

        $episodes = Arr::get($item, 'episodes', []);
        $episodeCount = is_array($episodes) ? count($episodes) : 0;

        // Create rest period object only once per period ID
        $target = EventObject::firstOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'rest_period',
            'type' => 'rest_period',
            'title' => 'Rest Mode Period (' . substr($id, 0, 8) . ')',
        ], [
            'time' => $startDay . ' ' . ($startTime ?? '00:00:00'),
            'content' => 'Rest mode period with episodes',
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
            'action' => 'had_rest_period',
            'value' => $encodedDuration,
            'value_multiplier' => $durationMultiplier,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'start_day' => $startDay,
                'end_day' => $endDay,
                'episode_count' => $episodeCount,
                'period_id' => $id,
            ],
            'target_id' => $target->id,
        ]);

        // Add episode information as blocks
        $biometrics = [];
        if ($episodeCount > 0) {
            $biometrics['episode_count'] = [
                'unit' => 'count',
                'title' => 'Episodes',
                'type' => 'episode_count',
            ];
            $item['episode_count'] = $episodeCount;
        }

        if (! empty($biometrics)) {
            $this->createBiometricBlocks($event, $item, $biometrics, $plugin);
        }

        // Add end time as timing block
        if ($endDay && $endTime) {
            $timingFields = ['end_time' => 'End Time'];
            $item['end_time'] = $endDay . ' ' . $endTime;
            $this->createSleepTimingBlocks($event, $item, $timingFields, $plugin);
        }
    }
}
