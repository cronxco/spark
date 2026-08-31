<?php

namespace App\Services\Flint;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\ActionProgress;
use App\Models\User;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\Routines\RoutineDriverManager;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class FlintRunDispatcher
{
    public function __construct(
        private EffectiveTimezoneResolver $timezones,
        private RoutineDriverManager $drivers,
    ) {}

    public function dispatch(
        User $user,
        mixed $skill = null,
        mixed $routine = null,
        mixed $date = null,
        mixed $period = 'morning',
        bool $sync = false,
    ): FlintDispatchResult {
        if (($skill !== null && ! is_string($skill)) || ($routine !== null && ! is_string($routine))) {
            throw new InvalidArgumentException('Skill and routine must be strings.');
        }
        if ($date !== null && ! is_string($date)) {
            throw new InvalidArgumentException('Date must use YYYY-MM-DD format.');
        }
        if (! is_string($period)) {
            throw new InvalidArgumentException('Period must be morning, afternoon, or evening.');
        }

        if ($skill !== null && $routine !== null
            && RoutineConfig::canonicalSkill($skill) !== RoutineConfig::canonicalSkill($routine)) {
            throw new InvalidArgumentException('The skill and deprecated routine values conflict.');
        }

        $requested = $skill ?? $routine ?? '';
        $canonical = RoutineConfig::canonicalSkill($requested);
        $resolvedRoutine = RoutineConfig::routineFor($requested);
        if (! $canonical || ! $resolvedRoutine) {
            throw new InvalidArgumentException("Unknown Flint skill '{$requested}'.");
        }
        if (! in_array($period, ['morning', 'afternoon', 'evening'], true)) {
            throw new InvalidArgumentException('Period must be morning, afternoon, or evening.');
        }

        $timezone = $this->timezones->timezoneFor($user);
        $localDate = $date ?: $this->timezones->today($user)->toDateString();
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $localDate, $timezone);
        } catch (Throwable) {
            $parsed = null;
        }
        if (! $parsed || $parsed->format('Y-m-d') !== $localDate) {
            throw new InvalidArgumentException('Date must use YYYY-MM-DD format.');
        }

        $runUuid = (string) Str::uuid();
        $progress = ActionProgress::createProgress(
            userId: $user->id,
            actionType: 'flint_skill',
            actionId: $runUuid,
            step: 'queued',
            message: 'Flint skill queued',
            details: [
                'run_uuid' => $runUuid,
                'skill' => $canonical,
                'routine' => $resolvedRoutine,
                'driver' => $this->drivers->driverName($resolvedRoutine),
                'local_date' => $localDate,
                'period' => $period,
            ],
        );

        $job = $resolvedRoutine === 'digest'
            ? new TriggerFlintDigestRoutineJob($user, $period, $localDate, $timezone, 'manual', null, true, $runUuid, $progress->id)
            : new TriggerFlintRoutineJob($user, $resolvedRoutine, $localDate, $timezone, true, $runUuid, $progress->id, $period);

        $sync ? dispatch_sync($job) : dispatch($job)->onQueue('flint');

        return new FlintDispatchResult(
            $runUuid,
            $progress,
            $canonical,
            $resolvedRoutine,
            $this->drivers->driverName($resolvedRoutine),
            $localDate,
            $period,
        );
    }
}
