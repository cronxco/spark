<?php

namespace App\Services\Flint;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FlintScheduleSettings
{
    public const ENABLED_KEYS = [
        'morning_digest_enabled',
        'evening_digest_enabled',
        'topics_enabled',
        'reading_list_enabled',
        'news_roundup_enabled',
        'money_weekly_enabled',
        'money_close_enabled',
    ];

    /** Routines whose schedule a user can edit. Digests stay on their fixed morning and evening slots. */
    public const EDITABLE_ROUTINES = ['topics', 'reading_list', 'news_roundup', 'money_weekly', 'money_close'];

    /** The most occurrences one routine may have in a day, so a cron cannot flood the queue. */
    public const MAX_DAILY_OCCURRENCES = 24;

    /**
     * Whether one routine is switched on. Each routine has its own switch and
     * an unset switch is off (decision D-F1: the old all-routines
     * `digests_enabled` key is no longer read).
     *
     * @param  array<string, mixed>  $settings
     */
    public static function enabled(array $settings, string $key): bool
    {
        return (bool) ($settings[$key] ?? false);
    }

    /**
     * Checks a schedule a user is saving and returns it in the stored shape. A malformed time or
     * cron is rejected here, so nothing invalid is ever stored and read back as the legacy slot.
     *
     * @param  array<string, mixed>  $input
     * @return array{frequency?: string, days?: list<string>, day_of_month?: int|string, time?: string, weekend_time?: string, times?: list<string>, cron?: string}
     *
     * @throws ValidationException
     */
    public static function validateSchedule(array $input): array
    {
        $input = array_filter($input, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        $frequency = $input['frequency'] ?? null;

        $validator = Validator::make($input, [
            'frequency' => ['nullable', 'in:daily,weekdays,weekly,monthly'],
            'days' => ['exclude_unless:frequency,weekly', 'required_if:frequency,weekly', 'array', 'min:1'],
            'days.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
            'day_of_month' => ['exclude_unless:frequency,monthly', 'required_if:frequency,monthly'],
            'time' => ['nullable', 'date_format:H:i'],
            'weekend_time' => ['nullable', 'date_format:H:i'],
            'times' => ['nullable', 'array', 'max:12'],
            'times.*' => ['date_format:H:i', 'distinct'],
            'cron' => ['nullable', 'string', 'max:100'],
        ], [
            'days.required_if' => 'Choose at least one day.',
            'day_of_month.required_if' => 'Choose a day of the month.',
            'times.*.date_format' => 'Times must look like 09:30.',
            'times.*.distinct' => 'Each time can only be listed once.',
        ]);

        $validator->after(function ($validator) use ($input, $frequency): void {
            $rule = $input['day_of_month'] ?? null;
            if ($frequency === 'monthly' && ! in_array($rule, ['last', 'first_working'], true) && ! (is_numeric($rule) && (int) $rule >= 1 && (int) $rule <= 28)) {
                $validator->errors()->add('day_of_month', 'Use a day from 1 to 28, the last day, or the first working day.');
            }
            if (isset($input['cron'])) {
                $cron = trim((string) $input['cron']);
                if (count(preg_split('/\s+/', $cron)) !== 5 || ! CronExpression::isValidExpression($cron)) {
                    $validator->errors()->add('cron', 'Enter a cron expression with five fields, for example 0 9 * * 1-5.');
                } elseif (self::cronOccurrencesPerDay($cron) > self::MAX_DAILY_OCCURRENCES) {
                    $validator->errors()->add('cron', 'That runs more than ' . self::MAX_DAILY_OCCURRENCES . ' times a day.');
                }
            }
            if ($frequency === null && ! isset($input['times']) && ! isset($input['cron'])) {
                $validator->errors()->add('frequency', 'Choose how often it runs, add a time, or enter a cron expression.');
            }
        });

        $valid = $validator->validate();
        if (isset($valid['day_of_month']) && is_numeric($valid['day_of_month'])) {
            $valid['day_of_month'] = (int) $valid['day_of_month'];
        }
        if (isset($valid['cron'])) {
            $valid['cron'] = trim((string) $valid['cron']);
        }
        if (isset($valid['times'])) {
            $valid['times'] = array_values($valid['times']);
            sort($valid['times']);
        }

        return $valid;
    }

    /**
     * The busiest day's number of occurrences across five weeks, so a cron limited to one weekday or
     * one day of the month is still measured on a day it runs.
     */
    private static function cronOccurrencesPerDay(string $cron): int
    {
        $expression = new CronExpression($cron);
        $cursor = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $end = $cursor->modify('+35 days');
        $perDay = [];
        for ($i = 0; $i < 1000; $i++) {
            $cursor = $expression->getNextRunDate($cursor, 0, false, 'UTC');
            if ($cursor >= $end) {
                break;
            }
            $day = $cursor->format('Y-m-d');
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            if ($perDay[$day] > self::MAX_DAILY_OCCURRENCES) {
                return $perDay[$day];
            }
        }

        return $perDay === [] ? 0 : max($perDay);
    }
}
