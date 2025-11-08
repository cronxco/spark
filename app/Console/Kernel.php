<?php

namespace App\Console;

use App\Jobs\Fetch\CheckCookieExpiryJob;
use App\Jobs\Fetch\RefreshExpiringCookies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Schedule Horizon snapshots only if Horizon is installed, and ensure single-server execution without overlap
        if (! class_exists(\Laravel\Horizon\Horizon::class) && ! class_exists(\Laravel\Horizon\Console\SnapshotCommand::class)) {
            return;
        }

        $schedule
            ->command('horizon:snapshot')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping();

        // Check cookie expiry daily at 6am
        $schedule
            ->job(new CheckCookieExpiryJob)
            ->dailyAt('06:00')
            ->onOneServer()
            ->withoutOverlapping();

        // Refresh expiring cookies daily at 2am
        $schedule
            ->job(new RefreshExpiringCookies)
            ->dailyAt('02:00')
            ->onOneServer()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
