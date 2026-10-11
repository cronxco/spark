<?php

namespace App\Services\Flint;

use App\Models\User;
use App\Services\EffectiveTimezoneResolver;
use Carbon\Carbon;

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
     * @return array{frequency: string, days?: array<int, string>, day_of_month?: int|string, time?: string, weekend_time?: string}|null
     */
    public function cadence(User $user, string $routine): ?array
    {
        $own = $user->settings['flint']['schedules'][$routine] ?? null;
        $cadence = is_array($own) ? $own : config("services.flint_routine.cadence.{$routine}");

        return is_array($cadence) && in_array($cadence['frequency'] ?? null, ['daily', 'weekdays', 'weekly', 'monthly'], true)
            ? $cadence
            : null;
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

    public function morningFallback(User $user): string
    {
        return (string) (($user->settings['flint']['morning_fallback'] ?? null)
            ?: config('services.flint_routine.morning_fallback'));
    }

    public function nextEligibleRun(User $user, string $routine): Carbon
    {
        $timezone = $this->timezones->timezoneFor($user);
        $now = $this->timezones->now($user);

        for ($offset = 0; $offset <= 35; $offset++) {
            $day = $now->copy()->addDays($offset);
            if (! $this->occursOn($user, $routine, $day)) {
                continue;
            }
            $candidate = Carbon::parse($day->toDateString().' '.$this->slot($user, $routine, $day), $timezone);
            if ($candidate->gt($now)) {
                return $candidate;
            }
        }

        return $now->copy()->addDay();
    }
}
