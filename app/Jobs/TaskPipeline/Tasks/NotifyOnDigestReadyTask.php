<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\Flint\SendDigestNotificationJob;
use App\Jobs\TaskPipeline\BaseTaskJob;
use Illuminate\Support\Facades\Log;

/**
 * Sends the "digest ready" notification when a Flint digest is actually written,
 * rather than on a fixed clock. Morning timing is now variable (it waits on the
 * Oura sleep-score event), so notification must follow generation.
 *
 * Triggered on creation of a `flint/had_summary` event.
 */
class NotifyOnDigestReadyTask extends BaseTaskJob
{
    protected function execute(): void
    {
        $event = $this->model;
        $user = $event->integration?->user;

        if (! $user) {
            return;
        }

        $period = $event->event_metadata['period'] ?? null;

        // SendDigestNotificationJob falls back to inferring the period from a
        // schedule-time string, so map the digest's period onto a representative
        // time for that path. Passing the event id keeps it from having to guess
        // at all: it announces exactly the digest that just landed.
        $scheduleTime = match ($period) {
            'morning' => '08:00',
            'afternoon' => '13:00',
            default => '19:00',
        };

        Log::info('Dispatching digest notification on digest readiness', [
            'user_id' => $user->id,
            'event_id' => $event->id,
            'period' => $period,
        ]);

        dispatch(new SendDigestNotificationJob($user, $scheduleTime, $period, $event->id))->onQueue('flint');
    }
}
