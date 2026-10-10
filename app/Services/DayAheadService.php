<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the forward-looking `ahead` section the mobile Looking ahead page
 * renders for a day that has not happened yet.
 *
 * Everything is read at query time from data Spark already holds: the
 * evening digest's `flint_day_context` block for that date, Google Calendar
 * events synced ahead of time, the Outline day note's task blocks, Flint
 * topic tasks and reviews, and recent Oura sleep and readiness.
 */
class DayAheadService
{
    /** Minutes between waking and the first commitment. */
    private const int WAKE_BUFFER_MINUTES = 60;

    /** Minutes allowed for falling asleep, added to the sleep need. */
    private const int SLEEP_LATENCY_MINUTES = 20;

    /** A night shorter than this is a nap and does not set the sleep need. */
    private const int MIN_NIGHT_SECONDS = 3 * 3600;

    private string $timezone = 'UTC';

    /**
     * @return array{
     *     date: string,
     *     headline: string,
     *     digest_event_id: string|null,
     *     weather: array<string, mixed>|null,
     *     first_commitment: array<string, mixed>|null,
     *     calendar: array<int, array<string, mixed>>,
     *     birthdays: array<int, array{title: string}>,
     *     plan: array<int, array<string, mixed>>,
     *     day_note_url: string|null,
     *     sleep_target: array<string, mixed>|null,
     * }
     */
    public function build(User $user, Carbon $date): array
    {
        $this->timezone = app(EffectiveTimezoneResolver::class)->timezoneForDate($user, $date->toDateString());
        $localDate = Carbon::parse($date->toDateString(), $this->timezone)->startOfDay();
        $dateString = $localDate->toDateString();
        $start = $localDate->copy()->utc();
        $end = $localDate->copy()->endOfDay()->utc();

        $dayContextBlock = $this->latestDayContext($user, $dateString);
        $dayContext = $dayContextBlock?->metadata['day_context'] ?? null;

        $calendarEvents = $this->calendarEvents($user, $start, $end);
        [$calendar, $syncedBirthdays] = $this->calendar($calendarEvents, $dayContext['calendar'] ?? null);

        $birthdays = collect($dayContext['birthdays'] ?? [])
            ->map(fn (array $birthday): array => ['title' => (string) ($birthday['title'] ?? '')])
            ->filter(fn (array $birthday): bool => $birthday['title'] !== '')
            ->whenEmpty(fn (): Collection => collect($syncedBirthdays))
            ->values()
            ->all();

        $dayNote = $this->dayNote($user, $start, $end);
        $plan = $this->plan($user, $dayNote, $dateString);
        $weather = $this->weather($dayContext['weather'] ?? null);
        $firstCommitment = collect($calendar)->first(fn (array $entry): bool => ! $entry['all_day'] && $entry['start'] !== null);

        return [
            'date' => $dateString,
            'headline' => $this->headline($calendar, $birthdays, $plan, $weather, $firstCommitment),
            'digest_event_id' => $dayContextBlock ? (string) $dayContextBlock->event_id : null,
            'weather' => $weather,
            'first_commitment' => $firstCommitment ? [
                'title' => $firstCommitment['title'],
                'start' => $firstCommitment['start'],
            ] : null,
            'calendar' => $calendar,
            'birthdays' => $birthdays,
            'plan' => $plan,
            'day_note_url' => $dayNote?->target?->url,
            'sleep_target' => $this->sleepTarget($user, $localDate, $firstCommitment),
        ];
    }

    /**
     * The newest day-context block a digest wrote about this date. The
     * evening digest dates its block to the following day, so the block's
     * own `day_context.date` decides, not the digest event's time.
     */
    private function latestDayContext(User $user, string $date): ?Block
    {
        return Block::query()
            ->where('block_type', 'flint_day_context')
            ->where('metadata->day_context->date', $date)
            ->whereHas('event', fn (Builder $event) => $event
                ->where('service', 'flint')
                ->whereHas('integration', fn (Builder $integration) => $integration->where('user_id', $user->id)))
            ->latest('created_at')
            ->first();
    }

    /** @return Collection<int, Event> */
    private function calendarEvents(User $user, Carbon $start, Carbon $end): Collection
    {
        return Event::query()
            ->withoutInternal()
            ->where('service', 'google_calendar')
            ->whereIn('action', ['had_event', 'had_all_day_event'])
            ->whereHas('integration', fn (Builder $integration) => $integration->where('user_id', $user->id))
            ->whereBetween('time', [$start, $end])
            ->with('target')
            ->orderBy('time')
            ->get();
    }

    /**
     * Tomorrow's schedule. When a digest has described the day its list is
     * authoritative, since it carries Will/Dan attribution and calendars
     * Spark does not sync; synced events then only add location, end time
     * and a link. Without a digest, the synced events are the schedule.
     *
     * @param  Collection<int, Event>  $events
     * @param  array<int, array<string, mixed>>|null  $digestCalendar
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{title: string}>}
     */
    private function calendar(Collection $events, ?array $digestCalendar): array
    {
        $birthdays = [];
        $synced = [];

        foreach ($events as $event) {
            $title = $event->target?->title ?? 'Event';
            $allDay = $event->action === 'had_all_day_event';

            if ($allDay && Str::contains(Str::lower($title), 'birthday')) {
                $birthdays[] = ['title' => $title];

                continue;
            }

            $synced[] = [
                'title' => $title,
                'start' => $allDay ? null : $this->local($event->time),
                'end' => $allDay || $event->value === null ? null : $this->local($event->time->copy()->addMinutes((int) $event->value)),
                'all_day' => $allDay,
                'location' => $event->event_metadata['location'] ?? null,
                'person' => null,
                'event_id' => (string) $event->id,
            ];
        }

        if (empty($digestCalendar)) {
            return [$this->sortCalendar($synced), $birthdays];
        }

        $entries = collect($digestCalendar)
            ->map(function (array $entry) use ($synced): array {
                $title = (string) ($entry['title'] ?? '');
                $allDay = (bool) ($entry['all_day'] ?? false);
                $start = $allDay || empty($entry['start']) ? null : $this->local(Carbon::parse($entry['start']));
                $match = $this->matchSynced($synced, $title, $start);

                return [
                    'title' => $title,
                    'start' => $start,
                    'end' => $match['end'] ?? null,
                    'all_day' => $allDay,
                    'location' => $match['location'] ?? null,
                    'person' => in_array($entry['person'] ?? null, ['will', 'dan'], true) ? $entry['person'] : null,
                    'event_id' => $match['event_id'] ?? null,
                ];
            })
            ->filter(fn (array $entry): bool => $entry['title'] !== '');

        return [$this->sortCalendar($this->mergeShared($entries)->all()), $birthdays];
    }

    /**
     * The digest lists a shared commitment once per person ("Will · Office",
     * "Dan · Office"). Entries at the same time whose titles match once the
     * name prefix is dropped become one entry attributed to both.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeShared(Collection $entries): Collection
    {
        return $entries
            ->groupBy(fn (array $entry): string => ($entry['start'] ?? 'all-day').'|'.Str::lower($this->withoutPersonPrefix($entry['title'])))
            ->map(function (Collection $group): array {
                $first = $group->first();
                $people = $group->pluck('person')->filter()->unique();

                if ($group->count() < 2 || $people->count() < 2) {
                    return $first;
                }

                return array_merge($first, [
                    'title' => $this->withoutPersonPrefix($first['title']),
                    'person' => 'both',
                    'end' => $group->pluck('end')->filter()->first(),
                    'location' => $group->pluck('location')->filter()->first(),
                    'event_id' => $group->pluck('event_id')->filter()->first(),
                ]);
            })
            ->values();
    }

    private function withoutPersonPrefix(string $title): string
    {
        return trim((string) preg_replace('/^(will|dan)\s*[·:\-–]\s*/iu', '', $title));
    }

    /**
     * @param  array<int, array<string, mixed>>  $synced
     * @return array<string, mixed>|null
     */
    private function matchSynced(array $synced, string $title, ?string $start): ?array
    {
        $needle = Str::lower($this->withoutPersonPrefix($title));

        foreach ($synced as $entry) {
            if (Str::lower($entry['title']) === $needle && $entry['start'] === $start) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function sortCalendar(array $entries): array
    {
        usort($entries, function (array $a, array $b): int {
            if ($a['all_day'] !== $b['all_day']) {
                return $a['all_day'] ? -1 : 1;
            }

            return strcmp((string) $a['start'], (string) $b['start']);
        });

        return array_values($entries);
    }

    private function dayNote(User $user, Carbon $start, Carbon $end): ?Event
    {
        return Event::query()
            ->where('service', 'outline')
            ->where('action', 'had_day_note')
            ->whereHas('integration', fn (Builder $integration) => $integration->where('user_id', $user->id))
            ->whereBetween('time', [$start, $end])
            ->with(['target', 'blocks'])
            ->latest('time')
            ->first();
    }

    /**
     * Day-note tasks, then Flint topic tasks due or up for review on the
     * day, then topics whose next review falls on it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function plan(User $user, ?Event $dayNote, string $date): array
    {
        $dayTasks = collect($dayNote?->blocks ?? [])
            ->where('block_type', 'day_task')
            ->reject(fn (Block $block): bool => (bool) ($block->metadata['removed'] ?? false))
            ->sortBy(fn (Block $block): int => (int) ($block->metadata['line_number'] ?? 0))
            ->map(fn (Block $block): array => [
                'id' => (string) $block->id,
                'title' => $block->title,
                'kind' => 'day_note',
                'done' => (bool) ($block->metadata['checked'] ?? false),
                'topic_id' => null,
                'topic_title' => null,
                'url' => $block->url,
            ]);

        $topicTasks = Block::query()
            ->where('block_type', 'flint_topic_task')
            ->where(fn (Builder $query) => $query
                ->where('metadata->due_on', $date)
                ->orWhere('metadata->review_on', $date))
            ->whereHas('event', fn (Builder $event) => $event
                ->where('service', 'flint')
                ->where('action', 'had_topic_task')
                ->whereHas('integration', fn (Builder $integration) => $integration->where('user_id', $user->id)))
            ->with('event.target')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Block $block): array => [
                'id' => (string) $block->id,
                'title' => $block->title,
                'kind' => 'topic_task',
                'done' => ($block->metadata['completed_at'] ?? null) !== null,
                'topic_id' => $block->event?->target_id ? (string) $block->event->target_id : null,
                'topic_title' => $block->event?->target?->title,
                'url' => null,
            ]);

        $topicReviews = EventObject::query()
            ->where('user_id', $user->id)
            ->where('concept', 'flint')
            ->where('type', 'topic')
            ->where('metadata->next_review_at', $date)
            ->where(fn (Builder $query) => $query
                ->whereNull('metadata->status')
                ->orWhere('metadata->status', 'active'))
            ->orderBy('title')
            ->get()
            ->map(fn (EventObject $topic): array => [
                'id' => (string) $topic->id,
                'title' => $topic->title,
                'kind' => 'topic_review',
                'done' => false,
                'topic_id' => (string) $topic->id,
                'topic_title' => $topic->title,
                'url' => null,
            ]);

        return $dayTasks->concat($topicTasks)->concat($topicReviews)->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $weather
     * @return array{location: string|null, condition: string|null, temp_high_c: float|int|null, rain_probability_pct: int|null}|null
     */
    private function weather(?array $weather): ?array
    {
        if (empty($weather)) {
            return null;
        }

        return [
            'location' => $weather['location'] ?? null,
            'condition' => $weather['condition'] ?? null,
            'temp_high_c' => $weather['temp_high_c'] ?? null,
            'rain_probability_pct' => isset($weather['rain_probability_pct']) ? (int) $weather['rain_probability_pct'] : null,
        ];
    }

    /**
     * A plain, factual line about the day: how much is on and when it
     * starts, any birthday, and the weather.
     *
     * @param  array<int, array<string, mixed>>  $calendar
     * @param  array<int, array{title: string}>  $birthdays
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>|null  $weather
     * @param  array<string, mixed>|null  $firstCommitment
     */
    private function headline(array $calendar, array $birthdays, array $plan, ?array $weather, ?array $firstCommitment): string
    {
        $parts = [];
        $eventCount = count($calendar);
        $openTasks = collect($plan)->where('done', false)->count();

        if ($eventCount === 0) {
            $parts[] = 'Nothing on the calendar';
        } else {
            $line = $eventCount === 1 ? '1 thing on' : "{$eventCount} things on";
            if ($firstCommitment) {
                $line .= ', first at '.Carbon::parse($firstCommitment['start'])->format('H:i');
            }
            $parts[] = $line;
        }

        if ($openTasks > 0) {
            $parts[] = $openTasks === 1 ? '1 task planned' : "{$openTasks} tasks planned";
        }

        foreach ($birthdays as $birthday) {
            $parts[] = $birthday['title'];
        }

        if ($weather && ($weather['condition'] ?? null)) {
            $line = $weather['condition'];
            if (($weather['temp_high_c'] ?? null) !== null) {
                $line .= ', '.round((float) $weather['temp_high_c']).'°';
            }
            if (($weather['rain_probability_pct'] ?? 0) >= 40) {
                $line .= ", {$weather['rain_probability_pct']}% chance of rain";
            }
            $parts[] = $line;
        }

        return implode('. ', $parts).'.';
    }

    /**
     * When to be in bed tonight to arrive rested for tomorrow's first
     * commitment. Only returned when there is a reason to say it: tomorrow
     * starts earlier than Will usually wakes, or readiness has been below
     * its recent average for three nights running.
     *
     * @param  array<string, mixed>|null  $firstCommitment
     * @return array{wake_by: string, bed_by: string, sleep_need_minutes: int, typical_wake: string|null, early_start: bool, readiness_low_nights: int}|null
     */
    private function sleepTarget(User $user, Carbon $localDate, ?array $firstCommitment): ?array
    {
        $nights = Event::query()
            ->where('service', 'oura')
            ->where('action', 'slept_for')
            ->whereHas('integration', fn (Builder $integration) => $integration->where('user_id', $user->id))
            ->where('time', '>=', $localDate->copy()->subDays(15)->utc())
            ->where('time', '<', $localDate->copy()->utc())
            ->get()
            ->filter(fn (Event $event): bool => (float) $event->formatted_value >= self::MIN_NIGHT_SECONDS);

        if ($nights->count() < 3) {
            return null;
        }

        $sleepNeedMinutes = (int) round($nights->map(fn (Event $event): float => (float) $event->formatted_value)->median() / 60);

        $wakeMinutes = $nights
            ->map(fn (Event $event): ?string => $event->event_metadata['end'] ?? null)
            ->filter()
            ->map(function (string $end): int {
                $local = Carbon::parse($end)->setTimezone($this->timezone);

                return $local->hour * 60 + $local->minute;
            });
        $typicalWakeMinutes = $wakeMinutes->isNotEmpty() ? (int) round($wakeMinutes->median()) : null;

        $readinessLowNights = $this->readinessLowNights($user, $localDate);

        if ($firstCommitment) {
            $wakeBy = Carbon::parse($firstCommitment['start'])->setTimezone($this->timezone)->subMinutes(self::WAKE_BUFFER_MINUTES);
        } elseif ($typicalWakeMinutes !== null) {
            $wakeBy = $localDate->copy()->addMinutes($typicalWakeMinutes);
        } else {
            return null;
        }

        $wakeByMinutes = (int) $localDate->diffInMinutes($wakeBy);
        $earlyStart = $typicalWakeMinutes !== null && $wakeByMinutes < $typicalWakeMinutes;

        if (! $earlyStart && $readinessLowNights < 3) {
            return null;
        }

        $bedBy = $wakeBy->copy()->subMinutes($sleepNeedMinutes + self::SLEEP_LATENCY_MINUTES);

        return [
            'wake_by' => $wakeBy->toIso8601String(),
            'bed_by' => $bedBy->toIso8601String(),
            'sleep_need_minutes' => $sleepNeedMinutes,
            'typical_wake' => $typicalWakeMinutes !== null
                ? sprintf('%02d:%02d', intdiv($typicalWakeMinutes, 60), $typicalWakeMinutes % 60)
                : null,
            'early_start' => $earlyStart,
            'readiness_low_nights' => $readinessLowNights,
        ];
    }

    /**
     * How many of the most recent readiness scores in a row sit below the
     * average of the thirty days before them.
     */
    private function readinessLowNights(User $user, Carbon $localDate): int
    {
        $scores = Event::query()
            ->where('service', 'oura')
            ->where('action', 'had_readiness_score')
            ->whereHas('integration', fn (Builder $integration) => $integration->where('user_id', $user->id))
            ->where('time', '>=', $localDate->copy()->subDays(33)->utc())
            ->where('time', '<', $localDate->copy()->utc())
            ->orderByDesc('time')
            ->get()
            ->map(fn (Event $event): float => (float) $event->formatted_value)
            ->values();

        if ($scores->count() < 7) {
            return 0;
        }

        $average = $scores->slice(3)->avg();
        $streak = 0;
        foreach ($scores as $score) {
            if ($score >= $average) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    private function local(CarbonInterface $time): string
    {
        return $time->copy()->setTimezone($this->timezone)->toIso8601String();
    }
}
