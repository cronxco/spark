<?php

namespace App\Services\Flint;

use App\Models\Integration;
use App\Models\TaskExecution;
use App\Models\User;
use App\Services\EffectiveTimezoneResolver;
use App\Services\TaskPipeline\TaskExecutionStore;
use Carbon\Carbon;
use Cron\CronExpression;

class FlintScheduleService
{
    private const SETTINGS = [
        'morning_digest' => ['enabled' => 'morning_digest_enabled', 'time' => 'morning_time_weekday'],
        'evening_digest' => ['enabled' => 'evening_digest_enabled', 'time' => 'evening_time'],
        'topics' => ['enabled' => 'topics_enabled', 'time' => 'topics_time'],
        'reading_list' => ['enabled' => 'reading_list_enabled', 'time' => 'reading_list_time'],
        'news_roundup' => ['enabled' => 'news_roundup_enabled', 'time' => 'news_roundup_time'],
        'money_weekly' => ['enabled' => 'money_weekly_enabled', 'time' => 'money_weekly_time'],
        'money_close' => ['enabled' => 'money_close_enabled', 'time' => 'money_close_time'],
    ];

    public function __construct(private EffectiveTimezoneResolver $timezones) {}

    public function enabled(User $user, string $routine): bool
    {
        $settings = $user->settings['flint'] ?? [];

        return FlintScheduleSettings::enabled($settings, self::SETTINGS[$routine]['enabled']);
    }

    public function slot(User $user, string $routine, ?Carbon $day = null): string
    {
        $settings = $user->settings['flint'] ?? [];
        $day ??= $this->timezones->now($user);
        $schedule = $this->cadence($user, $routine);
        $scheduled = $day->isWeekend() ? ($schedule['weekend_time'] ?? $schedule['time'] ?? null) : ($schedule['time'] ?? null);
        if (is_string($scheduled) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $scheduled)) {
            return $scheduled;
        }

        $key = self::SETTINGS[$routine]['time'];
        if ($routine === 'morning_digest' && $day->isWeekend()) {
            $key = 'morning_time_weekend';
        }

        return (string) ($settings[$key] ?? config("services.flint_routine.{$key}"));
    }

    /**
     * How often a routine runs: the user's `settings.flint.schedules.{routine}` entry, else the
     * routine's configured default, else null, which means every day (how every routine ran before
     * schedules existed, so nothing needs migrating).
     *
     * `frequency` is daily, weekdays, weekly (with `days`: mon..sun) or monthly (with `day_of_month`:
     * 1 to 28, `last` or `first_working`). `time` and `weekend_time` are optional HH:MM overrides.
     *
     * `times` is an optional list of HH:MM slots that replaces `time` and runs the routine several
     * times on each day `frequency` allows. `cron` is an optional 5-field expression that adds its
     * own occurrences and decides its own days, so a schedule may hold only a `cron`. Both are read in
     * the user's effective timezone.
     *
     * @return array{frequency?: string, days?: array<int, string>, day_of_month?: int|string, time?: string, weekend_time?: string, times?: array<int, string>, cron?: string}|null
     */
    public function cadence(User $user, string $routine): ?array
    {
        $own = $user->settings['flint']['schedules'][$routine] ?? null;
        $cadence = is_array($own) ? $own : config("services.flint_routine.cadence.{$routine}");

        if (! is_array($cadence)) {
            return null;
        }

        return in_array($cadence['frequency'] ?? null, ['daily', 'weekdays', 'weekly', 'monthly'], true)
            || $this->times($cadence) !== []
            || $this->cronExpression($cadence) !== null
            ? $cadence
            : null;
    }

    /**
     * Every occurrence of the routine on a local day, earliest first. `key` is null for a schedule
     * with a single `time`, so those keep the one-per-day idempotency keys they always had; with
     * `times` or `cron` it is the slot's HH:MM and each slot is its own occurrence.
     *
     * @return array<int, array{key: string|null, at: Carbon}>
     */
    public function occurrencesOn(User $user, string $routine, Carbon $day): array
    {
        $timezone = $this->timezones->timezoneFor($user);
        $cadence = $this->cadence($user, $routine);
        $times = $cadence === null ? [] : $this->times($cadence);
        $cron = $cadence === null ? null : $this->cronExpression($cadence);

        if ($times === [] && $cron === null) {
            return $this->occursOn($user, $routine, $day)
                ? [['key' => null, 'at' => Carbon::parse($day->toDateString() . ' ' . $this->slot($user, $routine, $day), $timezone)]]
                : [];
        }

        $found = [];
        if ($times !== [] && $this->occursOn($user, $routine, $day)) {
            foreach ($times as $time) {
                $found[$time] = Carbon::parse($day->toDateString() . ' ' . $time, $timezone);
            }
        }

        if ($cron !== null) {
            $cursor = Carbon::parse($day->toDateString(), $timezone)->startOfDay()->subSecond();
            for ($i = 0; $i < 1440; $i++) {
                $next = Carbon::instance($cron->getNextRunDate($cursor, 0, false, $timezone))->setTimezone($timezone);
                if ($next->toDateString() !== $day->toDateString()) {
                    break;
                }
                $found[$next->format('H:i')] ??= $next;
                $cursor = $next;
            }
        }

        ksort($found);

        return array_map(fn (string $key, Carbon $at): array => ['key' => $key, 'at' => $at], array_keys($found), array_values($found));
    }

    /**
     * The occurrence the dispatcher should consider right now: the latest one that has already
     * passed today. Earlier ones that were missed are not replayed, so a late tick never fires a
     * burst of runs.
     *
     * @return array{key: string|null, at: Carbon}|null
     */
    public function dueOccurrence(User $user, string $routine, Carbon $now): ?array
    {
        $due = array_values(array_filter(
            $this->occurrencesOn($user, $routine, $now),
            fn (array $occurrence): bool => $occurrence['at']->lte($now),
        ));

        return $due === [] ? null : $due[count($due) - 1];
    }

    /**
     * Whether a scheduled or manual run of the routine is still in flight, so another is not started
     * on top of it. A run counts as in flight while its execution is pending, accepted or retrying,
     * until it is older than `services.flint_routine.running_stale_minutes`.
     */
    public function isRunning(User $user, string $routine): bool
    {
        $integration = Integration::query()->where('user_id', $user->id)->where('service', 'flint')->oldest()->first();
        if ($integration === null) {
            return false;
        }

        $store = app(TaskExecutionStore::class);
        $execution = TaskExecution::query()
            ->forEntity($store->entityType($integration), (string) $integration->getKey())
            ->where('task_key', "flint_routine_{$routine}")
            ->first();
        if ($execution === null || ! in_array($execution->status, ['pending', 'accepted', 'retrying'], true)) {
            return false;
        }

        $since = $execution->started_at ?? $execution->updated_at;

        return $since === null
            || $since->gt(now()->subMinutes((int) config('services.flint_routine.running_stale_minutes', 45)));
    }

    /**
     * Whether the routine is due on this local day, before its time of day is considered.
     */
    public function occursOn(User $user, string $routine, Carbon $day): bool
    {
        $cadence = $this->cadence($user, $routine);

        return match ($cadence['frequency'] ?? 'daily') {
            'weekdays' => ! $day->isWeekend(),
            'weekly' => in_array(strtolower($day->format('D')), array_map('strtolower', (array) ($cadence['days'] ?? [])), true),
            'monthly' => $this->isMonthlyDay($cadence['day_of_month'] ?? 1, $day),
            default => true,
        };
    }

    public function morningFallback(User $user): string
    {
        return (string) (($user->settings['flint']['morning_fallback'] ?? null)
            ?: config('services.flint_routine.morning_fallback'));
    }

    public function nextEligibleRun(User $user, string $routine): Carbon
    {
        $now = $this->timezones->now($user);

        for ($offset = 0; $offset <= 35; $offset++) {
            $day = $now->copy()->addDays($offset);
            foreach ($this->occurrencesOn($user, $routine, $day) as $occurrence) {
                if ($occurrence['at']->gt($now)) {
                    return $occurrence['at'];
                }
            }
        }

        return $now->copy()->addDay();
    }

    /**
     * @param  array<string, mixed>  $cadence
     * @return array<int, string>
     */
    private function times(array $cadence): array
    {
        $valid = array_filter(
            (array) ($cadence['times'] ?? []),
            fn (mixed $time): bool => is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1,
        );

        return array_values(array_unique($valid));
    }

    /**
     * @param  array<string, mixed>  $cadence
     */
    private function cronExpression(array $cadence): ?CronExpression
    {
        $cron = $cadence['cron'] ?? null;

        return is_string($cron) && count(preg_split('/\s+/', trim($cron))) === 5 && CronExpression::isValidExpression($cron)
            ? new CronExpression($cron)
            : null;
    }

    private function isMonthlyDay(int|string $rule, Carbon $day): bool
    {
        if ($rule === 'last') {
            return $day->isSameDay($day->copy()->endOfMonth());
        }

        if ($rule === 'first_working') {
            $first = $day->copy()->startOfMonth();
            while ($first->isWeekend()) {
                $first->addDay();
            }

            return $day->isSameDay($first);
        }

        return $day->day === min(max((int) $rule, 1), $day->daysInMonth);
    }
}
