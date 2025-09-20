<?php

namespace App\Jobs\Webhook\AppleHealth;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Jobs\Base\BaseWebhookHookJob;
use App\Jobs\Data\AppleHealth\AppleHealthMetricData;
use App\Jobs\Data\AppleHealth\AppleHealthWorkoutData;

class AppleHealthWebhookHook extends BaseWebhookHookJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'apple_health';
    }

    protected function getJobType(): string
    {
        return 'webhook';
    }

    protected function validateWebhook(): void
    {
        $plugin = new AppleHealthPlugin;
        $plugin->validateWebhookSignature($this->webhookPayload, $this->headers, $this->integration);
    }

    protected function splitWebhookData(): array
    {
        $plugin = new AppleHealthPlugin;

        return $plugin->processWebhookData($this->webhookPayload, $this->headers, $this->integration);
    }

    protected function dispatchProcessingJobs(array $processingData): void
    {
        foreach ($processingData as $item) {
            if ($item['type'] === 'workout') {
                AppleHealthWorkoutData::dispatch($this->integration, $item['data']);
            } elseif ($item['type'] === 'metric') {
                AppleHealthMetricData::dispatch($this->integration, $item['data']);
            }
        }
    }
}
