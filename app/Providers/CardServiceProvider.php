<?php

namespace App\Providers;

use App\Cards\CardRegistry;
use App\Cards\Cards\Day\AfternoonCheckinCard;
use App\Cards\Cards\Day\CheckinHistoryCard;
use App\Cards\Cards\Day\DayIntroCard;
use App\Cards\Cards\Day\EveningIntroCard;
use App\Cards\Cards\Day\MorningCheckinCard;
use App\Cards\Cards\Day\MorningIntroCard;
use App\Cards\Cards\Day\OvernightStatsCard;
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
        CardRegistry::register('day', MorningIntroCard::class);
        CardRegistry::register('day', MorningCheckinCard::class);
        CardRegistry::register('day', OvernightStatsCard::class);
        CardRegistry::register('day', DayIntroCard::class);
        CardRegistry::register('day', AfternoonCheckinCard::class);
        CardRegistry::register('day', EveningIntroCard::class);
        CardRegistry::register('day', CheckinHistoryCard::class);
    }
}
