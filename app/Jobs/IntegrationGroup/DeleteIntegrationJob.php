<?php

namespace App\Jobs\IntegrationGroup;

use App\Models\Integration;

class DeleteIntegrationJob extends BaseIndividualDeletionJob
{
    public function handle(): void
    {
        $integration = Integration::find($this->recordId);

        if ($integration) {
            $integration->forceDelete();
            $this->logDeletion('integration', $this->recordId);
        }
    }
}
