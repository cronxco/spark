<?php

namespace App\Services\Actions\Handlers;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Models\Integration;
use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;

class SyncAllIntegrations implements ActionHandler
{
    public function handle(User $user, array $target): ActionOutcome
    {
        $dispatcher = new DispatchIntegrationFetchJobs;
        $integrations = 0;
        $jobs = 0;

        $user->integrations()->get()
            ->reject(fn (Integration $integration): bool => $integration->isPaused())
            ->each(function (Integration $integration) use ($dispatcher, &$integrations, &$jobs): void {
                $dispatched = $dispatcher->dispatch($integration);
                $jobs += $dispatched;
                $integrations += $dispatched > 0 ? 1 : 0;
            });

        if ($jobs === 0) {
            throw new ActionFailed(DispatchIntegrationFetchJobs::NOTHING_TO_DISPATCH, 'nothing_to_dispatch');
        }

        return ActionOutcome::accepted("Syncing {$integrations} integrations", [
            'integrations' => $integrations,
            'jobs_dispatched' => $jobs,
        ]);
    }
}
