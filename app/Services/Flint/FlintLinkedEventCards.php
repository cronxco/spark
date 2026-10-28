<?php

namespace App\Services\Flint;

use App\Http\Resources\Compact\CompactEventResource;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Services\MetricPresentation;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * The events a Flint digest's prose links to, shaped as cards.
 *
 * Flint writes every metric it quotes as a deep link — "a [sleep score of
 * 66](https://spark.cronx.co/events/{id})" — so the prose already says which
 * events it is about. Up to Speed and the web Flint page show those events as
 * compact cards under the paragraph that mentions them, and this is the one
 * place that turns the links into card payloads for both.
 *
 * Values are live. When a value changed after the digest was written (Oura
 * revises a sleep score through the morning), the card carries the value the
 * digest saw as `at_digest`, recovered from the event's changelog, so the
 * client can say "updated from 66" rather than silently contradicting the
 * sentence above it.
 */
class FlintLinkedEventCards
{
    /**
     * An event deep link, absolute or relative, in either the plural form
     * Flint writes or the singular one EntityReferenceResolver::linkify emits.
     */
    private const LINK_PATTERN = '~/events?/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?![0-9a-z-])~i';

    public function __construct(private MetricPresentation $presentation) {}

    /**
     * Linked event ids in the order the prose first mentions them.
     *
     * @return array<int, string>
     */
    public static function linkedIds(?string $markdown): array
    {
        if ($markdown === null || $markdown === '') {
            return [];
        }

        preg_match_all(self::LINK_PATTERN, $markdown, $matches);

        return collect($matches[1])
            ->map(fn (string $id): string => strtolower($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Cards for each digest's linked events, keyed by digest id.
     *
     * Only events with a value get a card: an article or a calendar entry is
     * already a link in the prose, and a card would only repeat its title.
     *
     * @param  Collection<int, Event>  $digests
     * @param  array<int, string>|Collection<int, string>  $integrationIds  the reader's integrations; a link to
     *                                                                      anyone else's event resolves to nothing
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function forDigests(Collection $digests, mixed $integrationIds, int|string $userId): array
    {
        $linkedIdsByDigest = $digests->mapWithKeys(fn (Event $digest): array => [
            $digest->id => self::linkedIds($digest->event_metadata['summary'] ?? null),
        ]);

        $allIds = $linkedIdsByDigest->flatten()->unique()->values();

        if ($allIds->isEmpty()) {
            return $linkedIdsByDigest->map(fn (): array => [])->all();
        }

        $events = Event::query()
            ->whereIn('id', $allIds)
            ->whereIn('integration_id', $integrationIds)
            ->whereNotNull('value')
            ->with(['integration', 'actor', 'target'])
            ->get()
            ->keyBy(fn (Event $event): string => strtolower($event->id));

        $statistics = $this->statisticsFor($events, $userId);
        $changes = $this->valueChanges($events, $digests);

        return $linkedIdsByDigest->map(function (array $ids, string $digestId) use ($events, $statistics, $changes, $digests): array {
            $digest = $digests->firstWhere('id', $digestId);

            return collect($ids)
                ->map(fn (string $id): ?Event => $events->get($id))
                ->filter()
                ->map(fn (Event $event): array => $this->card(
                    $event,
                    $statistics->get($this->metricKey($event)),
                    $this->valueAtDigest($event, $digest, $changes->get($event->id, collect())),
                ))
                ->values()
                ->all();
        })->all();
    }

    /**
     * @param  array{value: float|int|string, display_value: string}|null  $atDigest
     * @return array<string, mixed>
     */
    private function card(Event $event, ?MetricStatistic $statistic, ?array $atDigest): array
    {
        return [
            'kind' => "{$event->service}.{$event->action}",
            'event' => CompactEventResource::make($event)->resolve(request()),
            'context' => $statistic ? $this->context($statistic, (float) $event->formatted_value) : null,
            'at_digest' => $atDigest,
        ];
    }

    /**
     * One line against the reader's usual, naming the baseline as the design
     * language asks ("12 below your 30-day usual"). A reading inside the
     * normal range is neutral: the client draws a flat arrow and no colour,
     * whatever the metric's polarity.
     *
     * @return array{direction: string, valence: string, within_usual_range: bool, text: string}|null
     */
    private function context(MetricStatistic $statistic, float $value): ?array
    {
        if (! $statistic->hasValidStatistics() || $this->presentation->isOrdinal($statistic)) {
            return null;
        }

        $mean = (float) $statistic->mean_value;
        $usual = $statistic->baseline_window_days
            ? "your {$statistic->baseline_window_days}-day usual"
            : 'your usual';
        $withinUsualRange = ! $this->presentation->isAnomalous($statistic, $value);
        $difference = round(abs($value - $mean));

        if ($difference == 0) {
            return ['direction' => 'flat', 'valence' => 'neutral', 'within_usual_range' => true, 'text' => "Around {$usual}"];
        }

        $direction = $value > $mean ? 'up' : 'down';
        $formattedDifference = $this->presentation->formatValue($statistic, $difference);

        return [
            'direction' => $direction,
            'valence' => $withinUsualRange ? 'neutral' : $this->presentation->valence($statistic, $direction),
            'within_usual_range' => $withinUsualRange,
            'text' => sprintf('%s %s %s', $formattedDifference, $direction === 'up' ? 'above' : 'below', $usual),
        ];
    }

    /**
     * The value this digest quoted, when the event has changed since.
     *
     * The first value change logged after the digest was written holds, as
     * its "old" side, what the event read when the digest was written.
     *
     * @param  Collection<int, Activity>  $changes  this event's value changes, oldest first
     * @return array{value: float|int|string, display_value: string}|null
     */
    private function valueAtDigest(Event $event, ?Event $digest, Collection $changes): ?array
    {
        if (! $digest?->created_at) {
            return null;
        }

        $first = $changes->first(fn (Activity $activity): bool => $activity->created_at->gt($digest->created_at));

        if (! $first) {
            return null;
        }

        $old = $first->properties['old'] ?? [];
        $rawValue = $old['value'] ?? null;

        if ($rawValue === null) {
            return null;
        }

        $multiplier = (float) ($old['value_multiplier'] ?? $event->value_multiplier ?? 1);
        $value = in_array($multiplier, [0.0, 1.0], true)
            ? (float) $rawValue
            : (float) $rawValue / $multiplier;

        if (floor($value) === $value) {
            $value = (int) $value;
        }

        if ((float) $value === (float) $event->formatted_value) {
            return null;
        }

        return [
            'value' => $value,
            'display_value' => format_event_display_value($value, $event->value_unit, $event->service, $event->action),
        ];
    }

    /**
     * Logged value changes for these events since the earliest digest, oldest
     * first, grouped by event id.
     *
     * @param  Collection<string, Event>  $events
     * @param  Collection<int, Event>  $digests
     * @return Collection<string, Collection<int, Activity>>
     */
    private function valueChanges(Collection $events, Collection $digests): Collection
    {
        $since = $digests->pluck('created_at')->filter()->min();

        if ($events->isEmpty() || $since === null) {
            return collect();
        }

        return Activity::query()
            ->where('log_name', 'changelog')
            ->where('event', 'updated')
            ->where('subject_type', Event::class)
            ->whereIn('subject_id', $events->pluck('id'))
            ->where('created_at', '>', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Activity $activity): bool => array_key_exists('value', $activity->properties['old'] ?? []))
            ->groupBy('subject_id');
    }

    /**
     * @param  Collection<string, Event>  $events
     * @return Collection<string, MetricStatistic>
     */
    private function statisticsFor(Collection $events, int|string $userId): Collection
    {
        if ($events->isEmpty()) {
            return collect();
        }

        return MetricStatistic::query()
            ->where('user_id', $userId)
            ->whereIn('service', $events->pluck('service')->unique())
            ->whereIn('action', $events->pluck('action')->unique())
            ->get()
            ->keyBy(fn (MetricStatistic $statistic): string => "{$statistic->service}.{$statistic->action}.{$statistic->value_unit}");
    }

    private function metricKey(Event $event): string
    {
        return "{$event->service}.{$event->action}.{$event->value_unit}";
    }
}
