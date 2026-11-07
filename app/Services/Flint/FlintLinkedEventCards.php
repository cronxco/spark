<?php

namespace App\Services\Flint;

use App\Http\Resources\Compact\CompactEventResource;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Services\MetricPresentation;
use App\Support\EventCardKind;
use App\Support\FlintQuestion;
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
 *
 * Each card names its `layout` (see FlintEventCardDetails): daily metrics keep
 * the compact metric card and its comparison with the reader's baseline;
 * flights, calendar entries, payments, articles and Flint's own digests get a
 * card that says what they are, with no baseline line, since a one-off event has no usual. When
 * the reader has answered a question in the same digest that links the event,
 * the card carries that `answer`.
 */
class FlintLinkedEventCards
{
    /**
     * An event deep link, absolute or relative, in either the plural form
     * Flint writes or the singular one EntityReferenceResolver::linkify emits.
     */
    private const LINK_PATTERN = '~/events?/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?![0-9a-z-])~i';

    public function __construct(
        private MetricPresentation $presentation,
        private FlintEventCardDetails $details,
    ) {}

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
     * Events with a value get a card, and so do articles, whose card shows
     * the source's own headline and takeaways rather than repeating the
     * linked phrase. Anything else without a value is already said by the
     * prose and gets none.
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
            ->with(['integration', 'actor', 'target', 'blocks'])
            ->get()
            ->filter(fn (Event $event): bool => $event->value !== null || $this->details->isReading($event))
            ->keyBy(fn (Event $event): string => strtolower($event->id));

        $statistics = $this->statisticsFor($events, $userId);
        $changes = $this->valueChanges($events, $digests);

        $details = $events->map(fn (Event $event): array => $this->details->for($event, $userId));

        return $linkedIdsByDigest->map(function (array $ids, string $digestId) use ($events, $statistics, $changes, $digests, $details): array {
            $digest = $digests->firstWhere('id', $digestId);
            $answers = $this->answers($digest);

            return collect($ids)
                ->map(fn (string $id): ?Event => $events->get($id))
                ->filter()
                ->map(fn (Event $event): array => $this->card(
                    $event,
                    $details->get(strtolower($event->id)),
                    $statistics->get($this->metricKey($event)),
                    $this->valueAtDigest($event, $digest, $changes->get($event->id, collect())),
                    $answers[strtolower($event->id)] ?? null,
                ))
                ->values()
                ->all();
        })->all();
    }

    /**
     * @param  array{layout: string, details: array<string, mixed>|null}  $layout
     * @param  array{value: float|int|string, display_value: string}|null  $atDigest
     * @param  array{answer: string, note: string|null}|null  $answer
     * @return array<string, mixed>
     */
    private function card(Event $event, array $layout, ?MetricStatistic $statistic, ?array $atDigest, ?array $answer): array
    {
        $kind = "{$event->service}.{$event->action}";
        $pattern = EventCardKind::for($kind, null, null)['pattern'];
        $isMetric = $layout['layout'] === 'metric';

        return [
            'kind' => $kind,
            'layout' => $layout['layout'],
            'event' => CompactEventResource::make($event)->resolve(request()),
            'context' => $isMetric && $statistic ? $this->context($statistic, (float) $event->formatted_value, $pattern) : null,
            'at_digest' => $atDigest,
            'details' => $layout['details'],
            'answer' => $answer,
        ];
    }

    /**
     * The reader's answers to this digest's questions, keyed by the event
     * each question links to. A question links the fact it asks about ("That
     * [£159 Criterion Theatre Trust payment](…/events/{id}) …"), so the card
     * for that event can close the loop.
     *
     * @return array<string, array{answer: string, note: string|null}>
     */
    private function answers(?Event $digest): array
    {
        if ($digest === null) {
            return [];
        }

        $answers = [];

        foreach ($digest->blocks->filter(fn ($block): bool => FlintQuestion::isQuestion($block) && FlintQuestion::isAnswered($block)) as $block) {
            $metadata = $block->metadata ?? [];
            $answer = [
                'answer' => (string) $metadata['answer'],
                'note' => filled($metadata['answer_note'] ?? null) ? (string) $metadata['answer_note'] : null,
            ];

            foreach (self::linkedIds($metadata['question'] ?? null) as $id) {
                $answers[$id] ??= $answer;
            }
        }

        return $answers;
    }

    /**
     * One line against the reader's 30d baseline, in the compact card's own
     * words (design language §7.3): "12 below 30d trend" under a ring or a
     * bare value, "35m above 30d avg" under a bar, which also needs the mean
     * to place its Avg marker. A reading inside the normal range is neutral:
     * the client draws a flat arrow and no colour, whatever the metric's
     * polarity.
     *
     * @return array{direction: string, valence: string, within_usual_range: bool, text: string, mean: float}|null
     */
    private function context(MetricStatistic $statistic, float $value, string $pattern): ?array
    {
        if (! $statistic->hasValidStatistics() || $this->presentation->isOrdinal($statistic)) {
            return null;
        }

        $mean = (float) $statistic->mean_value;
        $window = $statistic->baseline_window_days ? "{$statistic->baseline_window_days}d " : '';
        $baseline = $window . ($pattern === 'bar' ? 'avg' : 'trend');
        $withinUsualRange = ! $this->presentation->isAnomalous($statistic, $value);
        $difference = round(abs($value - $mean));
        $context = ['mean' => round($mean, 2)];

        if ($difference == 0) {
            return $context + ['direction' => 'flat', 'valence' => 'neutral', 'within_usual_range' => true, 'text' => "Around {$baseline}"];
        }

        $direction = $value > $mean ? 'up' : 'down';
        $formattedDifference = $this->presentation->formatValue($statistic, $difference);

        return $context + [
            'direction' => $direction,
            'valence' => $withinUsualRange ? 'neutral' : $this->presentation->valence($statistic, $direction),
            'within_usual_range' => $withinUsualRange,
            'text' => sprintf('%s %s %s', $formattedDifference, $direction === 'up' ? 'above' : 'below', $baseline),
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
