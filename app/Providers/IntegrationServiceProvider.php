<?php

namespace App\Providers;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Integrations\Financial\FinancialPlugin;
use App\Integrations\GitHub\GitHubPlugin;
use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Integrations\Hevy\HevyPlugin;
use App\Integrations\Monzo\MonzoPlugin;
use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Outline\OutlinePlugin;
use App\Integrations\PluginRegistry;
use App\Integrations\Reddit\RedditPlugin;
use App\Integrations\Slack\SlackPlugin;
use App\Integrations\Spotify\SpotifyPlugin;
use App\Integrations\Task\TaskPlugin;
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
        PluginRegistry::register(HevyPlugin::class);
        PluginRegistry::register(GoCardlessBankPlugin::class);
        PluginRegistry::register(AppleHealthPlugin::class);
        PluginRegistry::register(RedditPlugin::class);
        PluginRegistry::register(TaskPlugin::class);
        PluginRegistry::register(OutlinePlugin::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
