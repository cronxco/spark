<?php

namespace App\Jobs\Data\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseProcessingJob;

class GoCardlessBalanceData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'balances';
    }

    protected function process(): void
    {
        $balanceData = $this->rawData;
        $plugin = new GoCardlessBankPlugin;

        $plugin->processBalanceData($this->integration, $balanceData);
    }
}
