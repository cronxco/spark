<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\FlintScheduleService;
use App\Services\Flint\FlintScheduleSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fires the morning Flint digest the moment the Oura sleep-score event lands,
 * provided the morning slot has already passed. This realises the "later of the
 * morning slot and the sleep event" barrier with low latency; the 15-minute
 * dispatcher in routes/console.php is the backstop for the other cases (sleep
 * arrived before the slot, or never arrived → fallback cutoff).
 *
 * Triggered on creation of an `oura/had_sleep_score` event.
 */
class DispatchMorningDigestOnSleepScoreTask extends BaseTaskJob
{
    protected function execute(): void
    {
        $event = $this->model;
        $user = $event->integration?->user;

        if (! $user) {
            return;
        }

        $settings = $user->settings['flint'] ?? [];
        if (! FlintScheduleSettings::enabled($settings, 'morning_digest_enabled')) {
            return;
        }

        $resolver = app(EffectiveTimezoneResolver::class);
        $tz = $resolver->timezoneFor($user);
        $now = $resolver->now($user);
        $today = $resolver->today($user)->toDateString();

        // Only react to the sleep score for the day we are waking into; ignore
        // historical back-fill (Oura pulls up to 7 days).
        $sleepDay = $event->event_metadata['day'] ?? null;
        if ($sleepDay !== $today) {
            return;
        }

        $morningTime = app(FlintScheduleService::class)->slot($user, 'morning_digest', $now);

        // Before the morning slot: let the dispatcher fire it at the slot instead.
        if ($now->lt(Carbon::parse($morningTime, $tz))) {
            return;
        }

        $marker = TriggerFlintDigestRoutineJob::markerKey($user->id, $today, 'morning');
        if (Cache::has($marker)) {
            return;
        }

        Log::info('Dispatching morning Flint digest on sleep-score arrival', [
            'user_id' => $user->id,
            'sleep_event_id' => $event->id,
            'local_date' => $today,
        ]);

        dispatch(new TriggerFlintDigestRoutineJob($user, 'morning', $today, $tz, 'sleep_score', $event->id))
            ->onQueue('flint');
    }
}
