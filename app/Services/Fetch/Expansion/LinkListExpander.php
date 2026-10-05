<?php

namespace App\Services\Fetch\Expansion;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Fetch\BookmarkCreator;
use App\Services\Fetch\FetchMetadata;
use App\Services\Fetch\Links\UrlCanonicalizer;
use App\Services\Fetch\UrlSafetyValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns the articles found in a list (a web page or a newsletter digest) into
 * bookmarks, deciding deterministically which are new.
 *
 * Every run records what it decided for each URL in a link-list block. For a
 * web list those blocks are the source's memory: a URL recorded as queued,
 * baseline_seen, existing, rejected or disabled is never treated as new again,
 * while URLs over the per-run cap are left unrecorded so they stay eligible.
 */
class LinkListExpander
{
    public const KIND_BASELINE = 'baseline';

    public const KIND_DELTA = 'delta';

    /** A new bookmark was created and its fetch dispatched. */
    public const STATUS_QUEUED = 'queued';

    /** Present on the first scan but not fetched (beyond the initial backfill). */
    public const STATUS_BASELINE_SEEN = 'baseline_seen';

    /** The user already had a bookmark for it; linked, not refetched. */
    public const STATUS_EXISTING = 'existing';

    /** Permanently excluded: unsafe URL, excluded domain, or the list itself. */
    public const STATUS_REJECTED = 'rejected';

    /** Bookmarked but left disabled because list auto-fetch is off. */
    public const STATUS_DISABLED = 'disabled';

    /** A transient failure; eligible again next run. */
    public const STATUS_RETRYABLE_FAILED = 'retryable_failed';

    public function __construct(
        private BookmarkCreator $bookmarks,
        private UrlSafetyValidator $urlSafety,
        private LinkSeenStateProjector $seenState,
    ) {}

    /**
     * Expand a list web page.
     *
     * @param  list<ListItem>  $items  In list order
     * @param  array<string, mixed>  $assessment  Audit record from the list assessment
     */
    public function expandBookmark(Integration $fetchIntegration, EventObject $list, array $items, array $assessment): ExpansionResult
    {
        $projection = $this->seenState->forBookmark($list);
        $coldStart = ! $projection['has_baseline'];

        $entries = $this->classify(
            user: $fetchIntegration->user,
            items: $items,
            seen: $projection['seen'],
            selfIdentity: UrlCanonicalizer::canonicalize((string) $list->url),
            coldStart: $coldStart,
        );

        $entries = $this->createChildren($fetchIntegration, $entries, [
            'discovered_from_object_id' => $list->id,
            'discovered_from_event_id' => null,
            'source_is_object' => true,
        ], $list);

        if ($entries === []) {
            return new ExpansionResult(null, [], $coldStart);
        }

        $event = $this->recordBookmarkExpansion($fetchIntegration, $list, $entries, $assessment, $coldStart, count($items));
        $this->seenState->forget($list);

        return new ExpansionResult($event, $entries, $coldStart);
    }

    /**
     * Expand a newsletter issue. Every issue is new, so there is no cold
     * start; existing bookmarks still dedupe.
     *
     * @param  list<ListItem>  $items
     * @param  array<string, mixed>  $assessment
     * @param  string  $blockType  Link-list block type owned by the newsletter plugin
     */
    public function expandIssue(Integration $fetchIntegration, Event $issue, array $items, array $assessment, string $blockType): ExpansionResult
    {
        $entries = $this->classify(
            user: $fetchIntegration->user,
            items: $items,
            seen: [],
            selfIdentity: null,
            coldStart: false,
            newItemLimit: count($items),
        );

        $entries = $this->createChildren($fetchIntegration, $entries, [
            'discovered_from_object_id' => null,
            'discovered_from_event_id' => $issue->id,
            'discovered_from_integration_id' => $issue->integration_id,
            'source_is_object' => false,
        ], $issue);

        if ($entries !== []) {
            $issue->createBlock([
                'title' => 'Articles Found',
                'block_type' => $blockType,
                'time' => $issue->time,
                'value' => $this->count($entries, self::STATUS_QUEUED),
                'value_multiplier' => 1,
                'value_unit' => 'articles',
                'metadata' => $this->blockMetadata(self::KIND_DELTA, $entries, $assessment, count($items)),
            ]);
        }

        return new ExpansionResult(null, $entries, false);
    }

    /**
     * Decide what happens to each item. Pure apart from lookups.
     *
     * @param  list<ListItem>  $items
     * @param  array<string, string>  $seen  canonical URL => status
     * @return list<array{url: string, canonical_url: string, title: ?string, status: string, child_id: ?string}>
     */
    private function classify(User $user, array $items, array $seen, ?string $selfIdentity, bool $coldStart, ?int $newItemLimit = null): array
    {
        $candidates = [];
        foreach ($items as $item) {
            $canonical = UrlCanonicalizer::canonicalize($item->url);

            if (isset($candidates[$canonical]) || isset($seen[$canonical])) {
                continue;
            }

            $candidates[$canonical] = $item;
        }

        $existing = $this->bookmarks->findMany($user->id, array_map(fn (ListItem $item): string => $item->url, array_values($candidates)));
        $backfill = max(0, (int) config('fetch.list_expansion.initial_backfill', 5));
        $cap = $newItemLimit ?? max(0, (int) config('fetch.list_expansion.max_new_per_run', 20));
        $newSlots = $coldStart ? $backfill : $cap;

        $entries = [];
        foreach ($candidates as $canonical => $item) {
            $entry = [
                'url' => $item->url,
                'canonical_url' => $canonical,
                'title' => $item->title,
                'status' => self::STATUS_QUEUED,
                'child_id' => null,
            ];

            if ($canonical === $selfIdentity || ! $this->isAllowed($user, $item->url)) {
                $entries[] = ['status' => self::STATUS_REJECTED] + $entry;

                continue;
            }

            if (isset($existing[$canonical])) {
                $entries[] = ['status' => self::STATUS_EXISTING, 'child_id' => (string) $existing[$canonical]->id] + $entry;

                continue;
            }

            if ($newSlots > 0) {
                $newSlots--;
                $entries[] = $entry;

                continue;
            }

            if ($coldStart) {
                $entries[] = ['status' => self::STATUS_BASELINE_SEEN] + $entry;
            }

            // Over the per-run cap: leave unrecorded so it stays eligible.
        }

        return $entries;
    }

    private function isAllowed(User $user, string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || $user->isFetchDiscoveryDomainExcluded($host)) {
            return false;
        }

        return $this->urlSafety->isSafe($url);
    }

    /**
     * Create bookmarks for queued entries, link sources to every new or
     * existing child, and dispatch fetches.
     *
     * @param  list<array{url: string, canonical_url: string, title: ?string, status: string, child_id: ?string}>  $entries
     * @param  array<string, mixed>  $discoveredFrom
     * @return list<array{url: string, canonical_url: string, title: ?string, status: string, child_id: ?string}>
     */
    private function createChildren(Integration $fetchIntegration, array $entries, array $discoveredFrom, EventObject|Event $source): array
    {
        $autoFetch = $fetchIntegration->user->getFetchListExpansionAutoFetchEnabled();
        $stagger = max(0, (int) config('fetch.list_expansion.stagger_seconds', 20));
        $perHost = [];
        $now = CarbonImmutable::now();

        foreach ($entries as $index => $entry) {
            if (! in_array($entry['status'], [self::STATUS_QUEUED, self::STATUS_EXISTING], true)) {
                continue;
            }

            try {
                if ($entry['status'] === self::STATUS_QUEUED) {
                    // The URL is a unique placeholder title until the first fetch
                    // (objects are unique per user and title); the list's title for
                    // the item is kept in metadata.
                    $result = $this->bookmarks->firstOrCreate($fetchIntegration->user_id, $entry['url'], [
                        'title' => $entry['url'],
                        'time' => $now,
                    ], array_merge([
                        'domain' => parse_url($entry['url'], PHP_URL_HOST),
                        'fetch_integration_id' => $fetchIntegration->id,
                        'subscription_source' => 'discovered',
                        'found_in' => 'list_expansion',
                        'via' => 'list_expansion',
                        'list_expansion_depth' => 1,
                        'list_item_title' => $entry['title'],
                        'fetch_mode' => 'once',
                        'enabled' => $autoFetch,
                        'discovered_from_integration_id' => $fetchIntegration->id,
                        'discovered_at' => $now->toIso8601String(),
                        'discovery_status' => 'pending',
                        'discovery_ignored' => false,
                        'last_checked_at' => null,
                        'last_changed_at' => null,
                        'content_hash' => null,
                        'fetch_count' => 0,
                        'is_discovered_url' => true,
                        'is_linkable' => false,
                        'fetch_dispatched_at' => null,
                    ], $discoveredFrom));
                } else {
                    $result = [
                        'bookmark' => EventObject::findOrFail($entry['child_id']),
                        'created' => false,
                    ];
                }

                $child = $result['bookmark'];
                $entries[$index]['child_id'] = (string) $child->id;

                $childMetadata = $child->metadata ?? [];
                $recoverDispatch = ! $result['created']
                    && ($childMetadata['via'] ?? null) === 'list_expansion'
                    && (string) ($childMetadata[$source instanceof EventObject ? 'discovered_from_object_id' : 'discovered_from_event_id'] ?? '') === (string) $source->id
                    && empty($childMetadata['fetch_dispatched_at'])
                    && (int) ($childMetadata['fetch_count'] ?? 0) === 0
                    && ($childMetadata['enabled'] ?? false);

                if (! $result['created'] && ! $recoverDispatch) {
                    $entries[$index]['status'] = self::STATUS_EXISTING;
                } elseif (! $autoFetch) {
                    $entries[$index]['status'] = self::STATUS_DISABLED;
                } else {
                    $host = strtolower((string) parse_url($entry['url'], PHP_URL_HOST));
                    $delay = ($perHost[$host] ?? 0) * $stagger;
                    $perHost[$host] = ($perHost[$host] ?? 0) + 1;

                    FetchSingleUrl::dispatch($fetchIntegration, (string) $child->id, $child->url)
                        ->delay($now->addSeconds($delay));
                    FetchMetadata::merge($child, ['fetch_dispatched_at' => $now->toIso8601String()]);
                    $entries[$index]['status'] = self::STATUS_QUEUED;
                }

                $this->link($fetchIntegration, $source, $entries[$index]);
            } catch (Throwable $e) {
                Log::warning('Fetch: Failed to prepare list item', [
                    'url' => $entry['url'],
                    'error' => $e->getMessage(),
                ]);
                $entries[$index]['status'] = self::STATUS_RETRYABLE_FAILED;
            }
        }

        return $entries;
    }

    /**
     * @param  array{url: string, child_id: ?string}  $entry
     */
    private function link(Integration $fetchIntegration, EventObject|Event $source, array $entry): void
    {
        if ($entry['child_id'] === null || (string) $source->id === $entry['child_id']) {
            return;
        }

        try {
            Relationship::createRelationship([
                'user_id' => $fetchIntegration->user_id,
                'from_type' => $source::class,
                'from_id' => (string) $source->id,
                'to_type' => EventObject::class,
                'to_id' => $entry['child_id'],
                'type' => 'linked_to',
                'metadata' => [
                    'url' => $entry['url'],
                    'via' => 'list_expansion',
                    'linked_at' => now()->toIso8601String(),
                    'fetch_integration_id' => $fetchIntegration->id,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('Fetch: Failed to link list item', [
                'source_id' => $source->id,
                'child_id' => $entry['child_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<array{url: string, canonical_url: string, title: ?string, status: string, child_id: ?string}>  $entries
     * @param  array<string, mixed>  $assessment
     */
    private function recordBookmarkExpansion(Integration $fetchIntegration, EventObject $list, array $entries, array $assessment, bool $coldStart, int $totalItems): Event
    {
        $now = CarbonImmutable::now();
        $newCount = $this->count($entries, self::STATUS_QUEUED) + $this->count($entries, self::STATUS_DISABLED);

        $actor = EventObject::firstOrCreate(
            [
                'user_id' => $fetchIntegration->user_id,
                'concept' => 'user',
                'type' => 'fetch_user',
                'title' => 'Fetch',
            ],
            [
                'time' => $now,
                'metadata' => ['service' => 'fetch'],
            ]
        );

        return DB::transaction(function () use ($fetchIntegration, $list, $entries, $assessment, $coldStart, $totalItems, $now, $newCount, $actor): Event {
            $runId = (string) Str::uuid();

            $event = Event::create([
                'source_id' => 'fetch_expand_' . $list->id . '_' . $runId,
                'integration_id' => $fetchIntegration->id,
                'service' => 'fetch',
                'domain' => 'knowledge',
                'action' => 'expanded',
                'time' => $now,
                'actor_id' => $actor->id,
                'target_id' => $list->id,
                'value' => $newCount,
                'value_multiplier' => 1,
                'value_unit' => 'articles',
                'target_metadata' => [
                    'title' => $list->title,
                    'url' => $list->url,
                    'kind' => 'list',
                    'new_count' => $newCount,
                    'total_count' => $totalItems,
                ],
                'event_metadata' => [
                    'url' => $list->url,
                    'kind' => 'list_expansion',
                    'expansion_run_id' => $runId,
                    'revision_model_version' => 1,
                    'cold_start' => $coldStart,
                ],
            ]);

            $event->createBlock([
                'title' => 'Articles Found',
                'block_type' => LinkSeenStateProjector::BLOCK_TYPE,
                'time' => $now,
                'value' => $newCount,
                'value_multiplier' => 1,
                'value_unit' => 'articles',
                'metadata' => $this->blockMetadata($coldStart ? self::KIND_BASELINE : self::KIND_DELTA, $entries, $assessment, $totalItems),
            ]);

            return $event;
        });
    }

    /**
     * @param  list<array{url: string, canonical_url: string, title: ?string, status: string, child_id: ?string}>  $entries
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    private function blockMetadata(string $kind, array $entries, array $assessment, int $totalItems): array
    {
        return [
            'kind' => $kind,
            'items' => $entries,
            'new_count' => $this->count($entries, self::STATUS_QUEUED) + $this->count($entries, self::STATUS_DISABLED),
            'total_count' => $totalItems,
            'assessment' => $assessment,
        ];
    }

    /**
     * @param  list<array{status: string}>  $entries
     */
    private function count(array $entries, string $status): int
    {
        return count(array_filter($entries, fn (array $entry): bool => $entry['status'] === $status));
    }
}
