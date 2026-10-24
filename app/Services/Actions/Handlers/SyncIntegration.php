<?php

namespace App\Services\Actions\Handlers;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;

class SyncIntegration implements ActionHandler
{
    public function handle(User $user, array $target): ActionOutcome
    {
        $integration = $user->integrations()->find($target['integration_id']) ?? throw ActionFailed::notFound('Integration');

        if ($integration->isPaused()) {
            throw new ActionFailed('Integration is paused.', 'paused');
        }

        $jobs = (new DispatchIntegrationFetchJobs)->dispatch($integration);

        if ($jobs === 0) {
            throw new ActionFailed(DispatchIntegrationFetchJobs::NOTHING_TO_DISPATCH, 'nothing_to_dispatch');
        }

        $integration->touch();

        return ActionOutcome::accepted('Sync queued', ['jobs_dispatched' => $jobs]);
    }
}
