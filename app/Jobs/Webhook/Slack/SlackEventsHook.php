<?php

namespace App\Jobs\Webhook\Slack;

use App\Integrations\Slack\SlackPlugin;
use App\Jobs\Base\BaseWebhookHookJob;
use App\Jobs\Data\Slack\SlackEventsData;
use Illuminate\Support\Facades\Log;

class SlackEventsHook extends BaseWebhookHookJob
{
    protected function getServiceName(): string
    {
        return 'slack';
    }

    protected function getJobType(): string
    {
        return 'events';
    }

    protected function validateWebhook(): void
    {
        $plugin = new SlackPlugin;
        $plugin->validateWebhookSignature($this->webhookPayload, $this->headers, $this->integration);
    }

    protected function splitWebhookData(): array
    {
        $plugin = new SlackPlugin;

        return $plugin->processWebhookData($this->webhookPayload, $this->headers, $this->integration);
    }

    protected function dispatchProcessingJobs(array $processingData): void
    {
        if (empty($processingData['events'])) {
            Log::info('Slack: No events to process after conversion', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch the data processing job
        SlackEventsData::dispatch($this->integration, $processingData);
    }
}
