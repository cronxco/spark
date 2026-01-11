<?php

namespace App\Providers;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Integrations\BlueSky\BlueSkyPlugin;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Integrations\Fetch\FetchPlugin;
use App\Integrations\Financial\FinancialPlugin;
use App\Integrations\Flint\FlintPlugin;
use App\Integrations\GitHub\GitHubPlugin;
use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Integrations\Goodreads\GoodreadsPlugin;
use App\Integrations\GoogleCalendar\GoogleCalendarPlugin;
use App\Integrations\Hevy\HevyPlugin;
use App\Integrations\Immich\ImmichPlugin;
use App\Integrations\Karakeep\KarakeepPlugin;
use App\Integrations\Monzo\MonzoPlugin;
use App\Integrations\Newsletter\NewsletterPlugin;
use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Outline\OutlinePlugin;
use App\Integrations\Oyster\OysterPlugin;
use App\Integrations\PluginRegistry;
use App\Integrations\Receipt\ReceiptPlugin;
use App\Integrations\Reddit\RedditPlugin;
use App\Integrations\Slack\SlackPlugin;
use App\Integrations\Spotify\SpotifyPlugin;
use App\Integrations\Task\TaskPlugin;
use App\Integrations\Untappd\UntappdPlugin;
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
        PluginRegistry::register(ImmichPlugin::class);
        PluginRegistry::register(GoCardlessBankPlugin::class);
        PluginRegistry::register(AppleHealthPlugin::class);
        PluginRegistry::register(RedditPlugin::class);
        PluginRegistry::register(TaskPlugin::class);
        PluginRegistry::register(OutlinePlugin::class);
        PluginRegistry::register(KarakeepPlugin::class);
        PluginRegistry::register(FetchPlugin::class);
        PluginRegistry::register(FlintPlugin::class);
        PluginRegistry::register(DailyCheckinPlugin::class);
        PluginRegistry::register(GoogleCalendarPlugin::class);
        PluginRegistry::register(BlueSkyPlugin::class);
        PluginRegistry::register(ReceiptPlugin::class);
        PluginRegistry::register(NewsletterPlugin::class);
        PluginRegistry::register(OysterPlugin::class);
        PluginRegistry::register(GoodreadsPlugin::class);
        PluginRegistry::register(UntappdPlugin::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
