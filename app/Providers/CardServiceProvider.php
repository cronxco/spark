<?php

namespace App\Providers;

use App\Cards\CardRegistry;
use Illuminate\Support\ServiceProvider;

class CardServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register Day stream cards
        CardRegistry::register('day', \App\Cards\Cards\Day\MorningIntroCard::class);
        CardRegistry::register('day', \App\Cards\Cards\Day\MorningCheckinCard::class);
        CardRegistry::register('day', \App\Cards\Cards\Day\OvernightStatsCard::class);
        CardRegistry::register('day', \App\Cards\Cards\Day\DayIntroCard::class);
        CardRegistry::register('day', \App\Cards\Cards\Day\AfternoonCheckinCard::class);
        CardRegistry::register('day', \App\Cards\Cards\Day\EveningIntroCard::class);
        CardRegistry::register('day', \App\Cards\Cards\Day\CheckinHistoryCard::class);
    }
}
