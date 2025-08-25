<?php

namespace App\Providers;

use App\Integrations\Financial\FinancialPlugin;
use App\Integrations\GitHub\GitHubPlugin;
use App\Integrations\Monzo\MonzoPlugin;
use App\Integrations\Oura\OuraPlugin;
use App\Integrations\PluginRegistry;
use App\Integrations\Slack\SlackPlugin;
use App\Integrations\Spotify\SpotifyPlugin;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register plugins
        PluginRegistry::register(FinancialPlugin::class);
        PluginRegistry::register(GitHubPlugin::class);
        PluginRegistry::register(SlackPlugin::class);
        PluginRegistry::register(SpotifyPlugin::class);
        PluginRegistry::register(OuraPlugin::class);
        PluginRegistry::register(MonzoPlugin::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
