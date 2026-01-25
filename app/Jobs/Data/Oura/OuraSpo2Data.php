<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Oura\Traits\HasOuraBlocks;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class OuraSpo2Data extends BaseProcessingJob
{
    use HasOuraBlocks;

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'spo2';
    }

    protected function process(): void
    {
        $spo2Items = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($spo2Items)) {
            return;
        }

        Log::info('OuraSpo2Data: Processing SpO2 data', [
            'integration_id' => $this->integration->id,
            'item_count' => count($spo2Items),
        ]);

        foreach ($spo2Items as $item) {
            $this->createSpo2Event($item, $plugin);
        }

        Log::info('OuraSpo2Data: Completed processing SpO2 data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function createSpo2Event(array $item, OuraPlugin $plugin): void
    {
        $day = $item['day'] ?? null;
        $id = $item['id'] ?? null;

        if (! $day || ! $id) {
            return;
        }

        $sourceId = "oura_spo2_{$this->integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)
            ->where('integration_id', $this->integration->id)
            ->first();
        if ($exists) {
            return;
        }

        $actor = $plugin->ensureUserProfile($this->integration);
        $target = $plugin->getStaticMetricObject(
            $this->integration,
            'daily_spo2',
            'Blood Oxygen Saturation (SpO2)',
            'Daily blood oxygen saturation measurement'
        );

        // Extract the correct SpO2 average from nested structure
        $spo2Average = Arr::get($item, 'spo2_percentage.average');
        [$encodedSpo2, $spo2Multiplier] = $plugin->encodeNumericValue($spo2Average);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_spo2',
            'value' => $encodedSpo2,
            'value_multiplier' => $spo2Multiplier,
            'value_unit' => 'percent',
            'event_metadata' => [
                'day' => $day,
                'measurement_id' => $id,
                'spo2_average' => $spo2Average,
            ],
            'target_id' => $target->id,
        ]);

        // Add breathing disturbance index as a biometric block
        $breathingDisturbanceIndex = $item['breathing_disturbance_index'] ?? null;
        if ($breathingDisturbanceIndex !== null) {
            $biometrics = [
                'breathing_disturbance_index' => [
                    'unit' => 'index',
                    'title' => 'Breathing Disturbance Index',
                    'type' => 'breathing_metric',
                ],
            ];

            $this->createBiometricBlocks($event, $item, $biometrics, $plugin);
        }
    }
}
