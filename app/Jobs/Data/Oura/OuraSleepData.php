<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Oura\Traits\HasOuraBlocks;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Facades\Log;

class OuraSleepData extends BaseProcessingJob
{
    use HasOuraBlocks;

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep';
    }

    protected function process(): void
    {
        $sleepItems = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($sleepItems)) {
            return;
        }

        Log::info('OuraSleepData: Processing sleep data', [
            'integration_id' => $this->integration->id,
            'sleep_count' => count($sleepItems),
        ]);

        foreach ($sleepItems as $item) {
            $this->createEnhancedSleepEvent($plugin, $item);
        }

        Log::info('OuraSleepData: Completed processing sleep data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    /**
     * Create enhanced sleep event with full API v2 field support
     */
    private function createEnhancedSleepEvent(OuraPlugin $plugin, array $item): void
    {
        $day = $item['day'] ?? null;
        if (! $day) {
            return;
        }

        $sourceId = "oura_sleep_{$this->integration->id}_{$day}";
        $exists = Event::where('source_id', $sourceId)
            ->where('integration_id', $this->integration->id)
            ->first();
        if ($exists) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);
        $target = $plugin->getStaticMetricObject(
            $this->integration,
            'daily_sleep_summary',
            'Daily Sleep Summary',
            'Daily sleep quality summary with comprehensive metrics'
        );

        $score = $item['score'] ?? null;
        [$encodedScore, $scoreMultiplier] = $plugin->encodeNumericValue($score);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'value' => $encodedScore,
            'value_multiplier' => $scoreMultiplier,
            'value_unit' => 'percent',
            'event_metadata' => [
                'day' => $day,
            ],
            'target_id' => $target->id,
        ]);

        // Add score contributors as blocks
        $contributors = $item['contributors'] ?? [];
        if (! empty($contributors)) {
            $this->createContributorBlocks($event, $contributors, $plugin);
        }

        // Add detailed sleep metrics
        $sleepMetrics = $this->getStandardSleepMetrics();
        $this->createSleepStageBlocks($event, $item, $sleepMetrics, $plugin);

        // Add timing information
        $timingFields = $this->getStandardSleepTimingFields();
        $this->createSleepTimingBlocks($event, $item, $timingFields, $plugin);

        // Add temperature data if available
        $biometrics = [
            'temperature_deviation' => ['unit' => 'celsius', 'title' => 'Temperature Deviation', 'type' => 'temperature'],
        ];
        $this->createBiometricBlocks($event, $item, $biometrics, $plugin);
    }
}
