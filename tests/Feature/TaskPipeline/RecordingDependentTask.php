<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\BaseTaskJob;

class RecordingDependentTask extends BaseTaskJob
{
    public static array $executed = [];

    protected function execute(): void
    {
        static::$executed[] = $this->model->id;
    }
}
