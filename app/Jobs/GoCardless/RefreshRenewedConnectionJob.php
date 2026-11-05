<?php

namespace App\Jobs\GoCardless;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Models\IntegrationGroup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshRenewedConnectionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $groupId, public string $generation) {}

    public function handle(DispatchIntegrationFetchJobs $dispatcher): void
    {
        $group = IntegrationGroup::find($this->groupId);
        if (! $group || ($group->auth_metadata['gocardless_generation'] ?? null) !== $this->generation) {
            return;
        }
        foreach ($group->integrations()->get() as $integration) {
            if (! $integration->isPaused()) {
                $dispatcher->dispatch($integration);
            }
        }
    }
}
