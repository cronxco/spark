<?php

namespace App\Console;

use App\Jobs\Data\Receipt\CleanupOldReceiptEmailsJob;
use App\Jobs\Fetch\CheckCookieExpiryJob;
use App\Jobs\Fetch\RefreshExpiringCookies;
use App\Jobs\Flint\RunContinuousBackgroundAnalysisJob;
use App\Jobs\Flint\RunDigestGenerationJob;
use App\Jobs\Flint\RunPatternDetectionJob;
use App\Jobs\Flint\RunPreDigestRefreshJob;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

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

        // Clean up old receipt emails from S3 daily at 3am (30 day retention)
        $schedule
            ->job(new CleanupOldReceiptEmailsJob)
            ->dailyAt('03:00')
            ->onOneServer()
            ->withoutOverlapping();

        // Flint continuous background analysis (every 15 minutes)
        $schedule
            ->call(function () {
                $this->dispatchContinuousBackgroundAnalysis();
            })
            ->everyFifteenMinutes()
            ->name('flint-continuous-background-analysis');

        // Flint digest dispatcher (runs every hour to check user-specific schedules)
        $schedule
            ->call(function () {
                $this->dispatchScheduledDigests();
            })
            ->hourly()
            ->name('flint-digest-dispatcher');

        // Flint pattern detection (weekly on Sundays at 04:00)
        $schedule
            ->call(function () {
                $this->dispatchPatternDetectionForAllUsers();
            })
            ->weeklyOn(0, '04:00')
            ->timezone('Europe/London')
            ->name('flint-pattern-detection');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Dispatch continuous background analysis for all users with Flint enabled
     */
    private function dispatchContinuousBackgroundAnalysis(): void
    {
        $users = User::query()
            ->whereHas('integrations', function ($query) {
                $query->where('service', 'flint')
                    ->whereRaw("configuration->>'continuous_analysis_enabled' != 'false'");
            })
            ->get();

        Log::info('Dispatching continuous background analysis', [
            'user_count' => $users->count(),
        ]);

        foreach ($users as $user) {
            dispatch(new RunContinuousBackgroundAnalysisJob($user));
        }
    }

    /**
     * Dispatch digest jobs for users based on their schedule preferences
     */
    private function dispatchScheduledDigests(): void
    {
        $users = User::query()
            ->whereHas('integrations', function ($query) {
                $query->where('service', 'flint');
            })
            ->get();

        $dispatched = 0;

        foreach ($users as $user) {
            $settings = $user->settings['flint'] ?? [];

            // Get user's timezone (default to Europe/London)
            $userTimezone = $settings['schedule_timezone'] ?? 'Europe/London';

            // Check if it's a weekday or weekend in user's timezone
            $now = now()->timezone($userTimezone);
            $isWeekend = $now->isWeekend();

            // Get schedule times for this day type
            $scheduleTimes = $isWeekend
                ? ($settings['schedule_times_weekend'] ?? ['08:00', '19:00'])
                : ($settings['schedule_times_weekday'] ?? ['06:00', '18:00']);

            // Check if current hour matches any schedule time
            $currentHourMinute = $now->format('H:00');

            foreach ($scheduleTimes as $scheduleTime) {
                // Parse schedule time (e.g., "06:00")
                $scheduleHour = substr($scheduleTime, 0, 2) . ':00';

                // If current hour matches schedule hour, dispatch digest
                if ($currentHourMinute === $scheduleHour) {
                    $period = $this->determinePeriod($scheduleTime);

                    Log::info('Dispatching scheduled digest', [
                        'user_id' => $user->id,
                        'period' => $period,
                        'schedule_time' => $scheduleTime,
                        'timezone' => $userTimezone,
                        'is_weekend' => $isWeekend,
                    ]);

                    // Dispatch pre-digest refresh 30 minutes before digest
                    dispatch(new RunPreDigestRefreshJob($user));

                    // Then dispatch digest generation
                    dispatch(new RunDigestGenerationJob($user, $period))
                        ->delay(now()->addMinutes(30));

                    $dispatched++;
                }
            }
        }

        if ($dispatched > 0) {
            Log::info('Scheduled digests dispatched', [
                'count' => $dispatched,
            ]);
        }
    }

    /**
     * Determine period (morning/afternoon/evening) based on time
     */
    private function determinePeriod(string $time): string
    {
        $hour = (int) substr($time, 0, 2);

        if ($hour < 12) {
            return 'morning';
        } elseif ($hour < 17) {
            return 'afternoon';
        } else {
            return 'evening';
        }
    }

    /**
     * Dispatch pattern detection for all users with Flint enabled
     */
    private function dispatchPatternDetectionForAllUsers(): void
    {
        $users = User::query()
            ->whereHas('integrations', function ($query) {
                $query->where('service', 'flint');
            })
            ->get();

        Log::info('Dispatching pattern detection', [
            'user_count' => $users->count(),
        ]);

        foreach ($users as $user) {
            dispatch(new RunPatternDetectionJob($user));
        }
    }
}
