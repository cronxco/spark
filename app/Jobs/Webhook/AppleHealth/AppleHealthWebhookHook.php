<?php

namespace App\Jobs\Webhook\AppleHealth;

use App\Jobs\Base\BaseWebhookHookJob;
use App\Jobs\Data\AppleHealth\AppleHealthMetricData;
use App\Jobs\Data\AppleHealth\AppleHealthWorkoutData;
use Exception;

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
        // Get the secret from the route parameter
        $routeSecret = $this->headers['x-webhook-secret'][0] ?? null;

        // Get the expected secret from the integration's account_id
        $expectedSecret = $this->integration->account_id;

        // Perform constant-time comparison to prevent timing attacks
        if (empty($routeSecret) || empty($expectedSecret)) {
            throw new Exception('Missing webhook secret');
        }

        if (! hash_equals($expectedSecret, $routeSecret)) {
            throw new Exception('Invalid webhook secret');
        }
    }

    protected function splitWebhookData(): array
    {
        $this->logWebhookPayload();

        $instanceType = (string) ($this->integration->instance_type ?? 'workouts');
        $processingData = [];

        // Extract data from the nested payload structure
        $payloadData = $this->webhookPayload['payload']['data'] ?? $this->webhookPayload;

        if ($instanceType === 'workouts') {
            $workouts = is_array($payloadData['workouts'] ?? null) ? $payloadData['workouts'] : [];
            foreach ($workouts as $workout) {
                if (is_array($workout)) {
                    $processingData[] = [
                        'type' => 'workout',
                        'data' => $workout,
                    ];
                }
            }
        }

        if ($instanceType === 'metrics') {
            $metrics = is_array($payloadData['metrics'] ?? null) ? $payloadData['metrics'] : [];
            foreach ($metrics as $metricEntry) {
                if (is_array($metricEntry)) {
                    $processingData[] = [
                        'type' => 'metric',
                        'data' => $metricEntry,
                    ];
                }
            }
        }

        return $processingData;
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
