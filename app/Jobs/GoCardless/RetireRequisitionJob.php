<?php

namespace App\Jobs\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\IntegrationGroup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RetireRequisitionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public string $groupId, public string $requisitionId) {}

    public function handle(GoCardlessBankPlugin $plugin): void
    {
        $group = IntegrationGroup::find($this->groupId);
        if (! $group || $group->account_id === $this->requisitionId ||
            ($group->auth_metadata['gocardless_pending']['requisition_id'] ?? null) === $this->requisitionId) {
            return;
        }
        $plugin->deleteOldRequisition($this->requisitionId);
    }
}
