<?php

namespace App\Jobs\Data\Monzo;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;

class MonzoAccountData extends BaseProcessingJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    public function getRawData()
    {
        return $this->rawData;
    }

    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'accounts';
    }

    protected function process(): void
    {
        // For accounts, we only need to upsert the account objects
        // Events are created by transaction/balance processing jobs
        $this->upsertAccountObject($this->rawData);

        // Also create day object for balance events if it doesn't exist
        $this->createDayObject();
    }

    private function upsertAccountObject(array $account): void
    {
        $title = match ($account['type'] ?? null) {
            'uk_retail' => 'Current Account',
            'uk_retail_joint' => 'Joint Account',
            'uk_monzo_flex' => 'Monzo Flex',
            'uk_prepaid' => 'Monzo OG',
            'uk_reward_account' => 'Monzo Rewards',
            default => 'Monzo Account',
        };

        $accountType = match ($account['type'] ?? null) {
            'uk_retail' => 'current_account',
            'uk_retail_joint' => 'current_account',
            'uk_monzo_flex' => 'credit_card',
            'uk_prepaid' => 'current_account',
            'uk_reward_account' => 'savings_account',
            default => 'other',
        };

        EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'account',
                'type' => 'monzo_account',
                'title' => $title,
            ],
            [
                'time' => now(),
                'content' => null,
                'metadata' => [
                    'name' => $title,
                    'provider' => 'Monzo',
                    'account_type' => $accountType,
                    'account_id' => $account['id'] ?? null,
                    'currency' => $account['currency'] ?? 'GBP',
                    'raw' => $account,
                ],
            ]
        );
    }

    private function createDayObject(): void
    {
        $date = now()->toDateString();

        EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $date,
            ],
            [
                'time' => $date . ' 00:00:00',
                'content' => null,
                'metadata' => ['date' => $date],
            ]
        );
    }
}
