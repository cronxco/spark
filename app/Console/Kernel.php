<?php

namespace App\Console;

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
