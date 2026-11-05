<?php

namespace App\Services\Actions\Handlers;

use App\Jobs\Metrics\DetectMetricTrendsJob;
use App\Models\User;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;

class DetectMetricTrends implements ActionHandler
{
    public function handle(User $user, array $target): ActionOutcome
    {
        DetectMetricTrendsJob::dispatch((string) $user->id);

        return ActionOutcome::accepted('Detection queued');
    }
}
