<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\Effects\Hevy\HevyAutoCoachEffect;
use App\Jobs\TaskPipeline\BaseTaskJob;
use RuntimeException;

class HevyAutoCoachTask extends BaseTaskJob
{
    /**
     * Execute the Hevy auto-coach effect via the task pipeline
     */
    protected function execute(): void
    {
        $integration = $this->model->integration ?? null;

        if (! $integration) {
            throw new RuntimeException('No integration found for HevyAutoCoachTask');
        }

        $effect = new HevyAutoCoachEffect($integration, [
            'task_key' => $this->task->key,
            'triggered_by' => 'task_pipeline',
        ]);

        $result = $effect->handle();

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException('HevyAutoCoachEffect failed: '.($result['message'] ?? 'unknown error'));
        }
    }
}
