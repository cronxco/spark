<?php

namespace App\Services\Fetch\Assessment;

use App\Models\EventObject;
use App\Services\Fetch\FetchMetadata;
use App\Services\Fetch\Links\LinkCandidateExtractor;
use App\Services\Fetch\Links\LinkClusterer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides, for one fetched page of a bookmark, whether to treat it as a list
 * of articles.
 *
 * Owns everything around the assessment itself: which bookmarks are
 * eligible, the per-bookmark mode (auto/off/force), reusing an earlier
 * verdict instead of asking again, the cheap structural gate, shadow mode,
 * and the `metadata.list_detection` memo. Returns an assessment only when the
 * caller should act on it.
 */
class ListPageDetector
{
    public const MODE_AUTO = 'auto';

    public const MODE_OFF = 'off';

    public const MODE_FORCE = 'force';

    /**
     * Sources whose pages must never be expanded (pages found by discovery or
     * by an earlier expansion), so expansion cannot chain into a crawl.
     */
    private const NON_EXPANDABLE_SOURCES = ['discovered', 'list_expansion'];

    public function __construct(
        private LinkCandidateExtractor $extractor,
        private ListPageAssessor $assessor,
    ) {}

    public static function isEnabled(): bool
    {
        return (bool) config('fetch.list_detection.enabled', false);
    }

    public static function isShadow(): bool
    {
        return (bool) config('fetch.list_detection.shadow', true);
    }

    public static function mode(EventObject $bookmark): string
    {
        $mode = $bookmark->metadata['list_detection']['mode'] ?? self::MODE_AUTO;

        return in_array($mode, [self::MODE_AUTO, self::MODE_OFF, self::MODE_FORCE], true) ? $mode : self::MODE_AUTO;
    }

    /**
     * Only pages the user chose (API, mobile, MCP, browser capture, Spotlight,
     * manual and legacy subscriptions) may be expanded.
     */
    public static function isEligibleForListExpansion(EventObject $bookmark): bool
    {
        $metadata = $bookmark->metadata ?? [];

        if (in_array($metadata['subscription_source'] ?? null, self::NON_EXPANDABLE_SOURCES, true)
            || in_array($metadata['via'] ?? null, self::NON_EXPANDABLE_SOURCES, true)
            || in_array($metadata['found_in'] ?? null, self::NON_EXPANDABLE_SOURCES, true)
            || (int) ($metadata['list_expansion_depth'] ?? 0) >= 1) {
            return false;
        }

        return self::mode($bookmark) !== self::MODE_OFF;
    }

    /**
     * @param  array{success: bool, reason: ?string, data: ?array}  $parsed  From ContentExtractor::parse()
     * @param  array{success: bool, reason: ?string}  $validation  From ContentExtractor::validateParsed()
     */
    public function detect(
        EventObject $bookmark,
        string $html,
        string $pageUrl,
        array $parsed,
        array $validation,
        ?string $capturedTitle = null,
    ): ?ListAssessment {
        if (! self::isEnabled() || ! self::isEligibleForListExpansion($bookmark) || $this->isAccessBarrier($validation)) {
            return null;
        }

        try {
            return $this->run($bookmark, $html, $pageUrl, $parsed, $validation, $capturedTitle);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array{success: bool, reason: ?string, data: ?array}  $parsed
     * @param  array{success: bool, reason: ?string}  $validation
     */
    private function run(EventObject $bookmark, string $html, string $pageUrl, array $parsed, array $validation, ?string $capturedTitle): ?ListAssessment
    {
        $mode = self::mode($bookmark);
        $memo = $bookmark->metadata['list_detection'] ?? [];
        $assessedAt = isset($memo['assessed_at']) ? CarbonImmutable::parse($memo['assessed_at']) : null;
        $logContext = ['webpage_id' => $bookmark->id, 'url' => $pageUrl];

        if ($mode === self::MODE_AUTO
            && ($memo['kind'] ?? null) === 'article'
            && $validation['success']
            && $assessedAt?->gt(now()->subDays((int) config('fetch.list_detection.negative_memo_days', 14)))) {
            return null;
        }

        $page = $this->extractor->fromHtml($html, $pageUrl);
        $clusterer = LinkClusterer::fromConfig();
        $clusters = $clusterer->cluster($page);

        if ($structured = $clusterer->structuredCluster($page)) {
            $clusters = array_slice(array_merge([$structured], $clusters), 0, max(1, (int) config('fetch.list_detection.max_clusters', 6)));
        }

        if (($memo['kind'] ?? null) === 'list'
            && $assessedAt?->gt(now()->subDays((int) config('fetch.list_detection.reassess_days', 7)))
            && ($reused = $this->assessor->reuse($clusters, (array) ($memo['signatures'] ?? [])))) {
            return self::isShadow() ? null : $reused;
        }

        $textLength = $parsed['success'] ? mb_strlen((string) ($parsed['data']['text_content'] ?? '')) : null;

        if ($mode !== self::MODE_FORCE && ! $clusterer->looksListLike($page, $clusters, $validation['success'] ? $textLength : null)) {
            return null;
        }

        $assessment = $this->assessor->assess($page, $clusters, [
            'readable' => $validation['success'],
            'text_length' => $textLength ?? 0,
            'excerpt' => $parsed['data']['excerpt'] ?? null,
            'title' => $parsed['data']['title'] ?? null,
        ], $capturedTitle, $logContext);

        $this->remember($bookmark, $assessment);

        Log::info('Fetch: List assessment', $logContext + [
            'status' => $assessment->status,
            'is_list' => $assessment->isList,
            'reason' => $assessment->reason,
            'accepted' => count($assessment->acceptedItems),
            'shadow' => self::isShadow(),
        ]);

        if (self::isShadow() || ! $assessment->isList) {
            return null;
        }

        return $assessment;
    }

    private function remember(EventObject $bookmark, ListAssessment $assessment): void
    {
        FetchMetadata::mutate($bookmark, function (array $metadata) use ($assessment): array {
            $memo = $metadata['list_detection'] ?? [];
            $memo['last_status'] = $assessment->status;
            $memo['last_assessment'] = $assessment->toArray();
            $memo['shadow'] = self::isShadow();

            if ($assessment->status === ListAssessment::STATUS_ASSESSED) {
                $memo['kind'] = $assessment->isList ? 'list' : 'article';
                $memo['signatures'] = $assessment->selectedSignatures();
                $memo['assessed_at'] = now()->toIso8601String();
                $memo['model_version'] = $assessment->jev?->model;
            }

            $metadata['list_detection'] = $memo;

            return $metadata;
        });
    }

    /**
     * @param  array{success: bool, reason: ?string}  $validation
     */
    private function isAccessBarrier(array $validation): bool
    {
        $reason = strtolower((string) ($validation['reason'] ?? ''));

        return str_contains($reason, 'robot') || str_contains($reason, 'captcha');
    }
}
