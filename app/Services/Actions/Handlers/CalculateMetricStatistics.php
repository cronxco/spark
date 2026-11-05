<?php

namespace App\Services\Actions\Handlers;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Models\User;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;

class CalculateMetricStatistics implements ActionHandler
{
    public function handle(User $user, array $target): ActionOutcome
    {
        CalculateMetricStatisticsJob::dispatch((string) $user->id);

        return ActionOutcome::accepted('Calculation queued');
    }
}
