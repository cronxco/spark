<?php

namespace App\Jobs\Data\GoCardless;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;

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

        // Handle rate-limited fallback response
        if (isset($accountData['status']) && $accountData['status'] === 'rate_limited') {
            $this->createRateLimitedAccountObject($accountData);

            return;
        }

        // Extract account details from the nested API response
        $accountDetails = $accountData['account'] ?? $accountData;

        // Create or update the account object
        $this->createAccountObject($accountDetails);

        // Update integration names if needed
        $this->updateIntegrationNames();
    }

    private function createAccountObject(array $accountDetails): void
    {
        $accountId = $accountDetails['resourceId'] ?? $accountDetails['id'] ?? 'unknown';
        $ownerName = $accountDetails['ownerName'] ?? 'Unknown';
        $iban = $accountDetails['iban'] ?? '';
        $currency = $accountDetails['currency'] ?? 'EUR';
        $cashAccountType = $accountDetails['cashAccountType'] ?? 'checking';

        $accountObject = EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'account',
                'type' => 'gocardless_account',
                'title' => $ownerName,
            ],
            [
                'integration_id' => $this->integration->id,
                'time' => now(),
                'content' => "IBAN: {$iban}",
                'metadata' => [
                    'account_id' => $accountId,
                    'owner_name' => $ownerName,
                    'iban' => $iban,
                    'currency' => $currency,
                    'cash_account_type' => $cashAccountType,
                    'provider' => 'GoCardless',
                    'account_type' => $this->mapAccountType($cashAccountType),
                    'raw_details' => $accountDetails,
                ],
            ]
        );
    }

    private function createRateLimitedAccountObject(array $accountData): void
    {
        $accountId = $accountData['id'] ?? 'unknown';

        EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'account',
                'type' => 'gocardless_account',
                'title' => $accountData['details'] ?? 'Rate Limited Account',
            ],
            [
                'integration_id' => $this->integration->id,
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
                ->where('type', 'gocardless_account')
                ->where('integration_id', $integration->id)
                ->first();

            if ($accountObject && $integration->name !== $accountObject->title) {
                $integration->update(['name' => $accountObject->title]);
            }
        }
    }

    private function mapAccountType(string $cashAccountType): string
    {
        return match ($cashAccountType) {
            'checking' => 'checking_account',
            'savings' => 'savings_account',
            'credit' => 'credit_card',
            default => 'checking_account',
        };
    }
}
