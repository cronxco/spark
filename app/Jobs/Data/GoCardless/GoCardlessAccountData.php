<?php

namespace App\Jobs\Data\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

class GoCardlessAccountData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'accounts';
    }

    protected function process(): void
    {
        $accountData = $this->rawData;
        $plugin = new GoCardlessBankPlugin;

        Log::info('GoCardlessAccountData: Processing account data', [
            'integration_id' => $this->integration->id,
        ]);

        // Handle rate-limited fallback response
        if (isset($accountData['status']) && $accountData['status'] === 'rate_limited') {
            $this->createRateLimitedAccountObject($accountData);

            return;
        }

        // Extract account details from the nested API response
        $accountDetails = $accountData['account'] ?? $accountData;

        // Create or update the account object using the plugin
        $plugin->upsertAccountObject($this->integration, $accountDetails);

        // Update integration names if needed
        $this->updateIntegrationNames();

        Log::info('GoCardlessAccountData: Completed processing account data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function createRateLimitedAccountObject(array $accountData): void
    {
        $accountId = $accountData['id'] ?? 'unknown';

        EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'account',
                'type' => 'bank_account',
                'title' => $accountData['details'] ?? 'Rate Limited Account',
            ],
            [
                'time' => now(),
                'content' => 'Account details temporarily unavailable due to rate limiting',
                'metadata' => [
                    'account_id' => $accountId,
                    'status' => 'rate_limited',
                    'rate_limit_error' => $accountData['rate_limit_error'] ?? 'Rate limit exceeded',
                    'provider' => 'GoCardless',
                    'account_type' => 'unknown',
                ],
            ]
        );
    }

    private function updateIntegrationNames(): void
    {
        $group = $this->integration->group;
        if (! $group) {
            return;
        }

        // Update all integrations in this group with account names
        $integrations = $group->integrations;
        foreach ($integrations as $integration) {
            $accountObject = EventObject::where('user_id', $integration->user_id)
                ->where('concept', 'account')
                ->where('type', 'bank_account')
                ->whereJsonContains('metadata->integration_id', $integration->id)
                ->first();

            if ($accountObject && $integration->name !== $accountObject->title) {
                $integration->update(['name' => $accountObject->title]);
            }
        }
    }
}
