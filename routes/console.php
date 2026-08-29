<?php

use App\Jobs\CheckIntegrationUpdates;
use App\Jobs\Fetch\CheckCookieExpiryJob;
use App\Jobs\Fetch\RefreshExpiringCookies;
use App\Jobs\Flint\RunPatternDetectionJob;
use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\TaskPipeline\DispatchRetrospectiveAnomalyTasksJob;
use App\Jobs\TaskPipeline\DispatchTrendDetectionTasksJob;
use App\Models\Event;
use App\Models\User;
use App\Services\EffectiveTimezoneResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Laravel\Horizon\Console\SnapshotCommand;
use Laravel\Horizon\Horizon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule integration update check job every minute
Schedule::job(new CheckIntegrationUpdates)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

// Detect metric trends daily via task pipeline
Schedule::job(new DispatchTrendDetectionTasksJob)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

// Detect retrospective metric anomalies daily via task pipeline
Schedule::job(new DispatchRetrospectiveAnomalyTasksJob)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

// Schedule Horizon snapshots only if Horizon is installed
if (class_exists(Horizon::class) && class_exists(SnapshotCommand::class)) {
    Schedule::command('horizon:snapshot')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping()
        ->sentryMonitor();
}

// Prune failed_jobs older than 30 days to prevent unbounded growth
Schedule::command('queue:prune-failed --hours=720')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

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

// Flint digest dispatcher (runs every 15 minutes to check for scheduled digests)
// New flow: -15min = agents run + digest generation, 0min = send notification
Schedule::call(function () {
    $resolver = app(EffectiveTimezoneResolver::class);

    // The id of the user's Oura sleep-score event for a local wake date, or null
    // if it hasn't been ingested yet. Used to gate the morning digest.
    $sleepScoreEventIdFor = fn (User $user, string $localDate): ?string => Event::query()
        ->where('service', 'oura')
        ->where('action', 'had_sleep_score')
        ->whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
        ->where('event_metadata->day', $localDate)
        ->value('id');

    $users = User::whereNotNull('settings->flint->digests_enabled')
        ->where('settings->flint->digests_enabled', '!=', false)
        ->get();

    foreach ($users as $user) {
        $settings = $user->settings['flint'] ?? [];

        // Schedule against the user's effective (acknowledged travel) timezone.
        $tz = $resolver->timezoneFor($user);
        $now = $resolver->now($user);
        $today = $resolver->today($user)->toDateString();
        $isWeekend = $now->isWeekend();

        $morningTime = $isWeekend
            ? ($settings['morning_time_weekend'] ?? config('services.flint_routine.morning_time_weekend'))
            : ($settings['morning_time_weekday'] ?? config('services.flint_routine.morning_time_weekday'));
        $eveningTime = $settings['evening_time'] ?? config('services.flint_routine.evening_time');
        $fallbackTime = $settings['morning_fallback'] ?? config('services.flint_routine.morning_fallback');

        // Evening digest: pure time gate at the configured evening slot.
        $eveningMarker = TriggerFlintDigestRoutineJob::markerKey($user->id, $today, 'evening');
        if ($now->gte(Carbon::parse($eveningTime, $tz)) && ! Cache::has($eveningMarker)) {
            dispatch(new TriggerFlintDigestRoutineJob($user, 'evening', $today, $tz, 'scheduled'))
                ->onQueue('flint');
        }

        // Morning digest: fire at the LATER of the morning slot and the Oura
        // sleep-score event, with a hard fallback cutoff if sleep never arrives.
        // (Low-latency firing when sleep lands after the slot is handled by
        // DispatchMorningDigestOnSleepScoreTask; this is the backstop.)
        $morningMarker = TriggerFlintDigestRoutineJob::markerKey($user->id, $today, 'morning');
        if ($now->gte(Carbon::parse($morningTime, $tz)) && ! Cache::has($morningMarker)) {
            $sleepEventId = $sleepScoreEventIdFor($user, $today);

            if ($sleepEventId !== null) {
                dispatch(new TriggerFlintDigestRoutineJob($user, 'morning', $today, $tz, 'scheduled', $sleepEventId))
                    ->onQueue('flint');
            } elseif ($now->gte(Carbon::parse($fallbackTime, $tz))) {
                dispatch(new TriggerFlintDigestRoutineJob($user, 'morning', $today, $tz, 'fallback'))
                    ->onQueue('flint');
            }
        }
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
        dispatch(new RunPatternDetectionJob($user))->onQueue('flint');
    }
})
    ->weeklyOn(0, '04:00')
    ->timezone('Europe/London')
    ->name('flint-pattern-detection')
    ->onOneServer()
    ->withoutOverlapping()
    ->sentryMonitor();
