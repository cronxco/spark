<?php

namespace App\Services\Fetch\Expansion;

use App\Models\Block;
use App\Models\EventObject;
use Illuminate\Support\Facades\Cache;

/**
 * Which list items a source has already dealt with.
 *
 * The `fetch_link_list` blocks written by each expansion are the authoritative,
 * append-only record; this folds them in event order (the latest status for a
 * URL wins) into a lookup. The fold is cached and invalidated whenever a new
 * block is written, so nothing about it has to live in mutable metadata.
 */
class LinkSeenStateProjector
{
    public const BLOCK_TYPE = 'fetch_link_list';

    /**
     * Statuses that mean "dealt with; do not treat as new again".
     */
    private const SEEN_STATUSES = [
        LinkListExpander::STATUS_QUEUED,
        LinkListExpander::STATUS_BASELINE_SEEN,
        LinkListExpander::STATUS_EXISTING,
        LinkListExpander::STATUS_REJECTED,
        LinkListExpander::STATUS_DISABLED,
    ];

    private const CACHE_TTL_SECONDS = 86400;

    /**
     * @return array{has_baseline: bool, seen: array<string, string>} seen: canonical URL => status
     */
    public function forBookmark(EventObject $bookmark): array
    {
        return Cache::remember(
            $this->cacheKey($bookmark),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->project($bookmark),
        );
    }

    public function forget(EventObject $bookmark): void
    {
        Cache::forget($this->cacheKey($bookmark));
    }

    /**
     * @return array{has_baseline: bool, seen: array<string, string>}
     */
    private function project(EventObject $bookmark): array
    {
        $hasBaseline = false;
        $statuses = [];

        Block::query()
            ->where('block_type', self::BLOCK_TYPE)
            ->whereHas('event', fn ($query) => $query
                ->where('target_id', $bookmark->id)
                ->where('service', 'fetch')
                ->where('action', 'expanded'))
            ->orderBy('time')
            ->orderBy('created_at')
            ->each(function (Block $block) use (&$hasBaseline, &$statuses): void {
                $metadata = $block->metadata ?? [];

                if (($metadata['kind'] ?? null) === LinkListExpander::KIND_BASELINE) {
                    $hasBaseline = true;
                }

                foreach ((array) ($metadata['items'] ?? []) as $item) {
                    if (is_array($item) && isset($item['canonical_url'], $item['status'])) {
                        $statuses[$item['canonical_url']] = $item['status'];
                    }
                }
            });

        return [
            'has_baseline' => $hasBaseline,
            'seen' => array_filter($statuses, fn (string $status): bool => in_array($status, self::SEEN_STATUSES, true)),
        ];
    }

    private function cacheKey(EventObject $bookmark): string
    {
        return 'fetch-list-seen:' . $bookmark->id;
    }
}
