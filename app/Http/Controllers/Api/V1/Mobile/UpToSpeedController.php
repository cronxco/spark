<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\Block;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class UpToSpeedController extends Controller
{
    /**
     * GET /api/v1/mobile/up-to-speed
     *
     * Returns an ordered, typed queue of catch-up items for the mobile
     * "Up to Speed" Stories flow.
     *
     * Ordering: flint_digest → check_in → anomaly → news_summary
     * All items are included; caught_up_at is populated for items that have
     * been marked via POST /up-to-speed/read (or via completion for check-ins).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $timezone = $user->timezone ?? 'UTC';
        $today = Carbon::today($timezone);
        $integrationIds = $user->integrations()->pluck('id');

        $digestItems = $this->buildDigestItems($user, $today, $integrationIds);
        $checkInItems = $this->buildCheckInItems($user, $today);
        $anomalyItems = $this->buildAnomalyItems($user, $today);
        $newsItems = $this->buildNewsItems($user, $integrationIds);

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
            ->whereIn('subject_id', $subjectIds)
            ->get()
            ->keyBy('subject_id');

        $enrich = function (array $item) use ($caughtUpMap): array {
            $subjectId = $item['_subject_id'] ?? null;
            $activity = $subjectId ? $caughtUpMap->get($subjectId) : null;
            $item['caught_up_at'] = $activity?->created_at?->toIso8601String();
            unset($item['_subject_id']);

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
    private function buildDigestItems(User $user, Carbon $today, mixed $integrationIds): array
    {
        $events = Event::whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->whereDate('time', $today)
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
                'payload' => [
                    'date' => Carbon::parse($event->time)->toDateString(),
                    'period' => $meta['period'] ?? null,
                    'title' => $meta['title'] ?? null,
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
    private function buildAnomalyItems(User $user, Carbon $today): array
    {
        $trends = MetricTrend::query()
            ->whereHas('metricStatistic', fn ($q) => $q->where('user_id', $user->id))
            ->anomalies()
            ->unacknowledged()
            ->whereDate('detected_at', $today)
            ->with('metricStatistic')
            ->get()
            ->filter(function (MetricTrend $trend): bool {
                $suppressUntil = $trend->metadata['suppress_until'] ?? null;

                return ! ($suppressUntil && Carbon::parse($suppressUntil)->isFuture());
            });

        return $trends->map(function (MetricTrend $trend): array {
            $stat = $trend->metricStatistic;

            $streakCount = $this->calculateStreakDays($trend, $stat);

            return [
                'id' => $trend->id,
                'type' => 'anomaly',
                'caught_up_at' => null,
                '_subject_id' => $trend->id,
                'payload' => [
                    'metric' => $stat->getIdentifier(),
                    'display_name' => $stat->getDisplayName(),
                    'type' => $trend->type,
                    'direction' => $trend->getDirection(),
                    'current_value' => round($trend->current_value, 2),
                    'baseline_value' => round($trend->baseline_value, 2),
                    'deviation' => round($trend->deviation, 2),
                    'streak_days' => $streakCount,
                    'detected_at' => $trend->detected_at->toIso8601String(),
                ],
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, mixed>  $integrationIds
     * @return array<int, array<string, mixed>>
     */
    private function buildNewsItems(User $user, mixed $integrationIds): array
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
            ->where('time', '>=', now()->subHours(48))
            ->whereHas('blocks', fn ($q) => $q->whereIn('block_type', $summaryBlockTypes))
            ->with(['blocks', 'target', 'actor'])
            ->orderBy('time', 'desc')
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
                    $payload['key_takeaways'] = $block->getContent();
                }
            }

            return [
                'id' => $event->id,
                'type' => 'news_summary',
                'caught_up_at' => null,
                '_subject_id' => $event->id,
                'payload' => $payload,
            ];
        })->all();
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
