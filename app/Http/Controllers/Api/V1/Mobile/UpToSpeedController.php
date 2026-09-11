<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\Block;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\MetricPresentation;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class UpToSpeedController extends Controller
{
    /**
     * Consecutive days of the same anomaly after which the baseline — not the
     * reading — is what has moved, and the metric should stop being raised.
     */
    private const REBASELINE_STREAK_DAYS = 7;

    /**
     * Standard deviations a first-day anomaly must clear to be worth raising.
     * Below this a single day's movement is noise.
     */
    private const SINGLE_DAY_DEVIATION_THRESHOLD = 3.0;

    /**
     * Hours of reading material to offer. A rolling window, so unaffected by
     * the reader's timezone.
     */
    private const NEWS_WINDOW_HOURS = 48;

    /**
     * Most news items to return. A heavy newsletter day previously returned
     * every one of them in a single unbounded response, which is neither a
     * sensible payload nor a readable queue.
     */
    private const DEFAULT_NEWS_LIMIT = 20;

    private const MAX_NEWS_LIMIT = 100;

    /**
     * GET /api/v1/mobile/up-to-speed
     *
     * Returns an ordered, typed queue of catch-up items for the mobile
     * "Up to Speed" Stories flow.
     *
     * Ordering: flint_digest → check_in → anomaly → news_summary
     * All items are included; caught_up_at is populated for items that have
     * been marked via POST /up-to-speed/read (or via completion for check-ins).
     * Read state is exposed, never enforced — the client decides what to show,
     * which is what lets it offer a recap of everything already seen.
     *
     * Query: include_acknowledged (bool) — also return anomalies the user has
     * acknowledged or suppressed. Off by default, since those are dismissed;
     * the client asks for them when building the recap so a mis-tapped
     * dismissal can be undone.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'include_acknowledged' => ['sometimes', 'boolean'],
            'news_limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_NEWS_LIMIT],
        ]);

        $user = $request->user();
        $timezone = $user->timezone ?? 'UTC';
        $today = Carbon::today($timezone);
        $integrationIds = $user->integrations()->pluck('id');
        $includeAcknowledged = (bool) ($validated['include_acknowledged'] ?? false);
        $newsLimit = (int) ($validated['news_limit'] ?? self::DEFAULT_NEWS_LIMIT);

        $digestItems = $this->buildDigestItems($user, $today, $integrationIds, $timezone);
        $checkInItems = $this->buildCheckInItems($user, $today);
        $anomalyItems = $this->buildAnomalyItems($user, $today, $timezone, $includeAcknowledged);
        $newsItems = $this->buildNewsItems($user, $integrationIds, $newsLimit);

        // Batch-fetch caught_up activities for all activity-log-tracked items
        $subjectIds = collect($digestItems)
            ->concat($anomalyItems)
            ->concat($newsItems)
            ->pluck('_subject_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $caughtUpMap = Activity::query()
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->where('event', 'caught_up')
            ->whereIn('subject_type', [Event::class, MetricTrend::class])
            ->whereIn('subject_id', $subjectIds)
            ->get()
            ->keyBy(fn (Activity $activity): string => "{$activity->subject_type}:{$activity->subject_id}");

        $enrich = function (array $item) use ($caughtUpMap): array {
            $subjectKey = $item['_subject_key'] ?? null;
            $activity = $subjectKey ? $caughtUpMap->get($subjectKey) : null;
            $item['caught_up_at'] = $activity?->created_at?->toIso8601String();
            unset($item['_subject_id'], $item['_subject_key']);

            return $item;
        };

        $items = collect($digestItems)->map($enrich)
            ->concat(collect($checkInItems))
            ->concat(collect($anomalyItems)->map($enrich))
            ->concat(collect($newsItems)->map($enrich))
            ->values();

        return response()->json(['items' => $items]);
    }

    /**
     * @param  Collection<int, mixed>  $integrationIds
     * @return array<int, array<string, mixed>>
     */
    private function buildDigestItems(User $user, Carbon $today, mixed $integrationIds, string $timezone): array
    {
        $events = Event::whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->whereBetween('time', $this->localDayRange($today, $timezone))
            ->with('blocks')
            ->orderBy('time', 'desc')
            ->get();

        return $events->map(function (Event $event): array {
            $meta = $event->event_metadata ?? [];

            return [
                'id' => $event->id,
                'type' => 'flint_digest',
                'caught_up_at' => null,
                '_subject_id' => $event->id,
                '_subject_key' => Event::class.':'.$event->id,
                'payload' => [
                    'date' => Carbon::parse($event->time)->toDateString(),
                    'period' => $meta['period'] ?? null,
                    'title' => $meta['title'] ?? null,
                    'kind' => $this->digestKind($event, $meta),
                    'summary' => $meta['summary'] ?? null,
                    'block_count' => $event->blocks->count(),
                    'unanswered_question_count' => $event->blocks->filter(
                        fn (Block $b) => $b->block_type === 'flint_user_question'
                            && is_null($b->metadata['answer'] ?? null)
                    )->count(),
                ],
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCheckInItems(User $user, Carbon $today): array
    {
        $dateString = $today->toDateString();
        $checkins = (new DailyCheckinPlugin)->getCheckinsForDate($user->id, $dateString);
        $items = [];

        foreach (['morning', 'afternoon'] as $period) {
            /** @var Event|null $event */
            $event = $checkins[$period];
            $items[] = [
                'id' => "{$period}:{$dateString}",
                'type' => 'check_in',
                'caught_up_at' => $event !== null ? $event->time->toIso8601String() : null,
                'payload' => [
                    'period' => $period,
                    'date' => $dateString,
                    'completed' => $event !== null,
                    'event_id' => $event?->id,
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildAnomalyItems(
        User $user,
        Carbon $today,
        string $timezone,
        bool $includeAcknowledged = false
    ): array {
        $trends = MetricTrend::query()
            ->whereHas('metricStatistic', fn ($q) => $q->where('user_id', $user->id))
            ->anomalies()
            ->when(! $includeAcknowledged, fn ($q) => $q->unacknowledged())
            ->whereBetween('detected_at', $this->localDayRange($today, $timezone))
            ->with('metricStatistic')
            ->get()
            ->filter(function (MetricTrend $trend) use ($includeAcknowledged): bool {
                if ($includeAcknowledged) {
                    return true;
                }

                $suppressUntil = $trend->metadata['suppress_until'] ?? null;

                return ! ($suppressUntil && Carbon::parse($suppressUntil)->isFuture());
            });

        $presentation = app(MetricPresentation::class);

        return $trends
            ->reject(fn (MetricTrend $trend): bool => $this->isNoise($trend, $presentation))
            ->map(function (MetricTrend $trend) use ($presentation): array {
                $stat = $trend->metricStatistic;
                $direction = $trend->getDirection();
                $streakCount = $this->calculateStreakDays($trend, $stat);
                $currentValue = round($trend->current_value, 2);
                $baselineValue = round($trend->baseline_value, 2);

                return [
                    'id' => $trend->id,
                    'type' => 'anomaly',
                    'caught_up_at' => null,
                    '_subject_id' => $trend->id,
                    '_subject_key' => MetricTrend::class.':'.$trend->id,
                    'payload' => [
                        'metric' => $stat->getIdentifier(),
                        'display_name' => $presentation->displayName($stat),
                        'domain' => $presentation->domain($stat),
                        'service' => $stat->service,
                        'unit' => $stat->value_unit,
                        'type' => $trend->type,
                        'direction' => $direction,
                        'valence' => $presentation->valence($stat, $direction),
                        'is_ordinal' => $presentation->isOrdinal($stat),
                        'current_value' => $currentValue,
                        'baseline_value' => $baselineValue,
                        'current_display' => $presentation->formatValue($stat, $currentValue),
                        'baseline_display' => $presentation->formatValue($stat, $baselineValue),
                        'deviation' => round($trend->deviation, 2),
                        'streak_days' => $streakCount,
                        'detected_at' => $trend->detected_at->toIso8601String(),
                        'acknowledged_at' => $trend->acknowledged_at?->toIso8601String(),
                    ],
                ];
            })->values()->all();
    }

    /**
     * Whether an anomaly is not worth raising.
     *
     * The briefing styleguide is explicit that a single-day movement is noise
     * unless the deviation is genuinely large, and that a topic should not keep
     * resurfacing merely because it has appeared for several days running. Both
     * were being ignored: a cardiovascular age that moved five years overnight
     * was shown on day one, and a balance sitting above its baseline for a
     * fortnight was still being announced as a surprise.
     *
     * A long streak means the baseline is stale, not that today is unusual —
     * that is surfaced as `baseline_stale` rather than as a fresh anomaly.
     */
    private function isNoise(MetricTrend $trend, MetricPresentation $presentation): bool
    {
        $stat = $trend->metricStatistic;

        if ($stat === null) {
            return true;
        }

        // The plugin has asked for this metric to stay out of Flint.
        if ($presentation->isExcludedFromFlint($stat)) {
            return true;
        }

        $streak = $this->calculateStreakDays($trend, $stat);
        $deviation = abs((float) $trend->deviation);

        // Seen every day for long enough that the baseline, not the reading, is
        // what has drifted.
        if ($streak >= self::REBASELINE_STREAK_DAYS) {
            return true;
        }

        // A one-off move has to be large to be worth interrupting for.
        if ($streak <= 1 && $deviation < self::SINGLE_DAY_DEVIATION_THRESHOLD) {
            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, mixed>  $integrationIds
     * @return array<int, array<string, mixed>>
     */
    private function buildNewsItems(User $user, mixed $integrationIds, int $limit): array
    {
        $summaryBlockTypes = [
            'fetch_tldr',
            'fetch_summary_paragraph',
            'fetch_key_takeaways',
            'newsletter_tldr',
            'newsletter_summary_paragraph',
            'newsletter_key_takeaways',
        ];

        $events = Event::whereIn('integration_id', $integrationIds)
            ->where('domain', 'knowledge')
            ->where(function ($q): void {
                $q->where('action', 'bookmarked')
                    ->orWhere(function ($q): void {
                        $q->where('service', 'newsletter')
                            ->where('action', 'received_post');
                    });
            })
            ->where('time', '>=', now()->subHours(self::NEWS_WINDOW_HOURS))
            ->whereHas('blocks', fn ($q) => $q->whereIn('block_type', $summaryBlockTypes))
            ->with(['blocks', 'target', 'actor'])
            ->orderBy('time', 'desc')
            ->limit($limit)
            ->get();

        return $events->map(function (Event $event) use ($summaryBlockTypes): array {
            $blocks = $event->blocks->keyBy('block_type');
            $payload = [
                'title' => $event->target?->title ?? $event->actor?->title ?? 'Untitled',
                'source' => $event->service,
                'url' => $event->url ?? $event->target?->url,
                'time' => $event->time->toIso8601String(),
                'tldr' => null,
                'summary' => null,
                'key_takeaways' => null,
            ];

            foreach ($summaryBlockTypes as $blockType) {
                $block = $blocks->get($blockType);
                if ($block === null) {
                    continue;
                }

                if (str_contains($blockType, 'tldr')) {
                    $payload['tldr'] = $block->getContent();
                } elseif (str_contains($blockType, 'summary')) {
                    $payload['summary'] = $block->getContent();
                } elseif (str_contains($blockType, 'key_takeaways')) {
                    $payload['key_takeaways'] = $this->normaliseKeyTakeaways($block->getContent());
                }
            }

            return [
                'id' => $event->id,
                'type' => 'news_summary',
                'caught_up_at' => null,
                '_subject_id' => $event->id,
                '_subject_key' => Event::class.':'.$event->id,
                'payload' => $payload,
            ];
        })->all();
    }

    /**
     * The UTC instants bounding a local calendar day.
     *
     * `whereDate()` compares against the stored UTC date, so pairing it with a
     * timezone-aware Carbon::today() silently mixed two different notions of
     * "today" — an evening digest could land on the wrong side of midnight for
     * anyone east or west of UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function localDayRange(Carbon $localDay, string $timezone): array
    {
        $start = $localDay->copy()->startOfDay()->setTimezone('UTC');
        $end = $localDay->copy()->endOfDay()->setTimezone('UTC');

        return [$start, $end];
    }

    /**
     * What sort of digest this is: the daily briefing, a news roundup, or a
     * reading list.
     *
     * The client was deciding this by looking for "news" or "reading list" in
     * the title and inspecting block types, which means presentation depended
     * on how a digest happened to be named. Deriving it once here keeps every
     * surface agreeing, and gives digests somewhere to declare it explicitly
     * later without another round of guessing.
     *
     * @param  array<string, mixed>  $meta
     */
    private function digestKind(Event $event, array $meta): string
    {
        $declared = $meta['kind'] ?? null;
        if (is_string($declared) && in_array($declared, ['briefing', 'news_roundup', 'reading_list'], true)) {
            return $declared;
        }

        $title = Str::lower((string) ($meta['title'] ?? ''));

        if (Str::contains($title, ['reading list', 'saved to read'])) {
            return 'reading_list';
        }

        if (Str::contains($title, ['news', 'roundup'])) {
            return 'news_roundup';
        }

        $contentBlocks = $event->blocks->filter(
            fn (Block $block): bool => ! in_array($block->block_type, ['flint_editorial_note', 'flint_user_question'], true)
        );

        if ($contentBlocks->isNotEmpty() && $contentBlocks->every(fn (Block $block): bool => $block->block_type === 'flint_news')) {
            return 'news_roundup';
        }

        return 'briefing';
    }

    /**
     * Normalise a key-takeaways block into a clean list of strings.
     *
     * The block stores a JSON-encoded array whose entries sometimes carry a
     * literal markdown bullet and sometimes do not, depending on which
     * summariser wrote them. Clients were each re-deriving the same repair.
     *
     * @return array<int, string>|null
     */
    private function normaliseKeyTakeaways(mixed $content): ?array
    {
        if ($content === null) {
            return null;
        }

        $items = is_array($content) ? $content : json_decode((string) $content, true);

        if (! is_array($items)) {
            // Not a JSON array — treat it as bullet-per-line prose.
            $items = preg_split('/\R+/', (string) $content) ?: [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $text = trim(preg_replace('/^\s*[-*\x{2022}]\s+/u', '', (string) $item) ?? '');

            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return $clean === [] ? null : $clean;
    }

    private function calculateStreakDays(MetricTrend $trend, MetricStatistic $stat): int
    {
        $recentAnomalies = MetricTrend::where('metric_statistic_id', $stat->id)
            ->anomalies()
            ->where('detected_at', '<=', $trend->detected_at)
            ->where('detected_at', '>=', $trend->detected_at->copy()->subDays(30))
            ->orderByDesc('detected_at')
            ->get();

        $streakCount = 0;
        $lastDate = $trend->detected_at;

        foreach ($recentAnomalies as $t) {
            if ($t->detected_at->diffInDays($lastDate) > 1) {
                break;
            }

            $streakCount++;
            $lastDate = $t->detected_at;
        }

        return $streakCount;
    }
}
