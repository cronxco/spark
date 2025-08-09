<?php

namespace App\Providers;

use App\Integrations\PluginRegistry;
use App\Integrations\GitHub\GitHubPlugin;
use App\Integrations\Slack\SlackPlugin;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register plugins
        PluginRegistry::register(GitHubPlugin::class);
        PluginRegistry::register(SlackPlugin::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
