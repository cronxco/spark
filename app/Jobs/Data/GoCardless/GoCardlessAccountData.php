<?php

namespace App\Jobs\Data\GoCardless;

use App\Services\GoCardlessAccounts;
use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseProcessingJob;
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

            return;
        }

        // Extract account details from the nested API response
        $accountDetails = GoCardlessAccounts::normalize($accountData, (string) $this->integration->configuration['account_id']);

        // Create or update the account object using the plugin
        $plugin->upsertAccountObject($this->integration, $accountDetails);

        // Update integration names if needed
        $this->updateIntegrationNames();

        Log::info('GoCardlessAccountData: Completed processing account data', [
            'integration_id' => $this->integration->id,
        ]);
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
            $accountObject = app(GoCardlessAccounts::class)->find(
                $integration->user_id, $integration->configuration['account_id'] ?? '');
            if ($accountObject && $integration->name !== $accountObject->title) {
                $integration->update(['name' => $accountObject->title]);
            }
        }
    }
}
