<?php

use App\Jobs\CheckIntegrationUpdates;
use App\Jobs\Data\Receipt\CleanupOldReceiptEmailsJob;
use App\Jobs\Fetch\CheckCookieExpiryJob;
use App\Jobs\Fetch\RefreshExpiringCookies;
use App\Jobs\Flint\RunPatternDetectionJob;
use App\Jobs\Flint\RunPreDigestRefreshJob;
use App\Jobs\Flint\SendDigestNotificationJob;
use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricTrendsJob;
use App\Jobs\Metrics\DetectRetrospectiveMetricAnomaliesJob;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule integration update check job every minute
Schedule::job(new CheckIntegrationUpdates)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

// Calculate metric statistics hourly
Schedule::job(new CalculateMetricStatisticsJob)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

// Detect metric trends daily
Schedule::job(new DetectMetricTrendsJob)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

// Detect retrospective metric anomalies daily
Schedule::job(new DetectRetrospectiveMetricAnomaliesJob)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

// Schedule Horizon snapshots only if Horizon is installed
if (class_exists(\Laravel\Horizon\Horizon::class) && class_exists(\Laravel\Horizon\Console\SnapshotCommand::class)) {
    Schedule::command('horizon:snapshot')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping()
        ->sentryMonitor();
}

// Check cookie expiry daily at 6am
Schedule::job(new CheckCookieExpiryJob)
    ->dailyAt('06:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->sentryMonitor();

// Refresh expiring cookies daily at 2am
Schedule::job(new RefreshExpiringCookies)
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->sentryMonitor();

// Clean up old receipt emails from S3 daily at 3am (30 day retention)
Schedule::job(new CleanupOldReceiptEmailsJob)
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->sentryMonitor();

// Flint digest dispatcher (runs every 15 minutes to check for scheduled digests)
// New flow: -15min = agents run + digest generation, 0min = send notification
Schedule::call(function () {
    $users = User::whereNotNull('settings->flint->digests_enabled')
        ->where('settings->flint->digests_enabled', '!=', false)
        ->get();

    $preDigestDispatched = 0;
    $notificationDispatched = 0;

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

        foreach ($scheduleTimes as $scheduleTime) {
            // Parse schedule time (e.g., "06:00")
            $scheduleCarbon = Carbon::parse($scheduleTime, $userTimezone);
            $minutesUntil = $now->diffInMinutes($scheduleCarbon, false);

            // If digest scheduled in the next 15 minutes or sooner, run pre-digest refresh
            if ($minutesUntil >= 1 && $minutesUntil <= 15) {
                Log::info('Dispatching pre-digest refresh (15 min before digest)', [
                    'user_id' => $user->id,
                    'schedule_time' => $scheduleTime,
                    'timezone' => $userTimezone,
                    'is_weekend' => $isWeekend,
                ]);

                dispatch(new RunPreDigestRefreshJob($user, $scheduleTime));
                // Job will auto-chain to RunDigestGenerationJob when agents complete

                $preDigestDispatched++;
            }

            // If digest scheduled right now, send notification
            // Allow a small window of -5 to 0 minutes to account for scheduling delays
            if ($minutesUntil >= -5 && $minutesUntil <= 0) {
                Log::info('Dispatching digest notification', [
                    'user_id' => $user->id,
                    'schedule_time' => $scheduleTime,
                    'timezone' => $userTimezone,
                    'is_weekend' => $isWeekend,
                ]);

                dispatch(new SendDigestNotificationJob($user, $scheduleTime));

                $notificationDispatched++;
            }
        }
    }

    if ($preDigestDispatched > 0 || $notificationDispatched > 0) {
        Log::info('Flint digest jobs dispatched', [
            'pre_digest_count' => $preDigestDispatched,
            'notification_count' => $notificationDispatched,
        ]);
    }
})
    ->everyFifteenMinutes()
    ->name('flint-digest-dispatcher')
    ->onOneServer()
    ->withoutOverlapping()
    ->sentryMonitor();

// Flint pattern detection (weekly on Sundays at 04:00)
Schedule::call(function () {
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
})
    ->weeklyOn(0, '04:00')
    ->timezone('Europe/London')
    ->name('flint-pattern-detection')
    ->onOneServer()
    ->withoutOverlapping()
    ->sentryMonitor();
