<?php

namespace App\Integrations\Contracts;

use App\Services\TaskPipeline\TaskDefinition;

interface SupportsTaskPipeline
{
    /**
     * Get task definitions for this plugin
     *
     * @return array<TaskDefinition>
     */
    public static function getTaskDefinitions(): array;
}
