<?php

namespace App\Jobs\Webhook\AppleHealth;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Jobs\Base\BaseWebhookHookJob;
use App\Jobs\Data\AppleHealth\AppleHealthMetricData;
use App\Jobs\Data\AppleHealth\AppleHealthWorkoutData;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;

class AppleHealthWebhookHook extends BaseWebhookHookJob
{
    public function __construct(array $webhookPayload, array $headers, Integration $integration)
    {
        parent::__construct($webhookPayload, $headers, $integration);
        $this->onQueue('pull');
    }

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
            Log::info('Dispatching Apple Health processing job', [
                'integration_id' => $this->integration->id,
                'type' => $item['type'] ?? null,
                'service' => $this->getServiceName(),
            ]);
            if ($item['type'] === 'workout') {
                AppleHealthWorkoutData::dispatch($this->integration, $item['data'])->onQueue('pull');
            } elseif ($item['type'] === 'metric') {
                AppleHealthMetricData::dispatch($this->integration, $item['data'])->onQueue('pull');
            }
        }
    }
}
