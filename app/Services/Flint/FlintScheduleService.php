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
        $key = self::SETTINGS[$routine]['time'];
        if ($routine === 'morning_digest' && $day->isWeekend()) {
            $key = 'morning_time_weekend';
        }

        return (string) ($settings[$key] ?? config("services.flint_routine.{$key}"));
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

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $now->copy()->addDays($offset);
            $candidate = Carbon::parse($day->toDateString() . ' ' . $this->slot($user, $routine, $day), $timezone);
            if ($candidate->gt($now)) {
                return $candidate;
            }
        }

        return $now->copy()->addDay();
    }
}
