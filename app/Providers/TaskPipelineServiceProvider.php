<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\TaskPipeline\TaskRegistry;
use App\Services\TaskPipeline\TaskDefinition;

class TaskPipelineServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        $this->registerCoreTasks();
        $this->registerPluginTasks();
    }

    /**
     * Register core task definitions
     */
    protected function registerCoreTasks(): void
    {
        // Core tasks will be registered here in Phase 2
        // For now, we're just setting up the infrastructure
    }

    /**
     * Register tasks from plugins that support the task pipeline
     */
    protected function registerPluginTasks(): void
    {
        // Plugin task registration will be implemented when we have a plugin registry
        // Plugins implementing SupportsTaskPipeline will be auto-discovered
    }
}
