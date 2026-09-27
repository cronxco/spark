<?php

namespace App\Services\Fetch;

use App\Models\EventObject;
use App\Services\Fetch\Links\UrlCanonicalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Single place that finds or creates a user's `fetch_webpage` bookmark.
 *
 * Bookmarks are matched on their dedupe identity (`metadata.canonical_url`)
 * as well as the exact URL, so `?utm_source=…` variants of a page resolve to
 * the same bookmark. There is no unique index on (user_id, url), so creation
 * is serialised per user + identity with a cache lock; every creation path
 * (API, mobile, MCP, Spotlight, discovery, list expansion) goes through here
 * so a lock taken by one path also protects the others.
 */
class BookmarkCreator
{
    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    /**
     * Find an existing bookmark for the URL.
     */
    public function find(string $userId, string $url): ?EventObject
    {
        return $this->findMany($userId, [$url])[UrlCanonicalizer::canonicalize($url)] ?? null;
    }

    /**
     * Find existing bookmarks for many URLs at once.
     *
     * @param  list<string>  $urls
     * @return array<string, EventObject> keyed by canonical URL
     */
    public function findMany(string $userId, array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $canonicalByUrl = [];
        foreach ($urls as $url) {
            $canonicalByUrl[$url] = UrlCanonicalizer::canonicalize($url);
        }
        $canonicals = array_values(array_unique($canonicalByUrl));

        $found = [];

        $this->bookmarks($userId)
            ->where(function (Builder $query) use ($canonicalByUrl, $canonicals): void {
                $query->whereIn('url', array_keys($canonicalByUrl))
                    ->orWhereIn('metadata->canonical_url', $canonicals);
            })
            ->orderBy('created_at')
            ->get()
            ->each(function (EventObject $bookmark) use (&$found): void {
                $canonical = $bookmark->metadata['canonical_url'] ?? UrlCanonicalizer::canonicalize((string) $bookmark->url);
                $found[$canonical] ??= $bookmark;
            });

        $missing = array_values(array_diff($canonicals, array_keys($found)));

        if ($missing !== []) {
            foreach ($this->findLegacy($userId, $missing) as $canonical => $bookmark) {
                $found[$canonical] ??= $bookmark;
            }
        }

        return $found;
    }

    /**
     * Find the bookmark for a URL or create it, serialised per identity.
     *
     * @param  array<string, mixed>  $attributes  Column values for a new bookmark (title, time, …)
     * @param  array<string, mixed>  $metadata  Metadata for a new bookmark
     * @return array{bookmark: EventObject, created: bool}
     */
    public function firstOrCreate(string $userId, string $url, array $attributes = [], array $metadata = []): array
    {
        $canonical = UrlCanonicalizer::canonicalize($url);

        return Cache::lock($this->lockKey($userId, $canonical), self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($userId, $url, $canonical, $attributes, $metadata): array {
                $existing = $this->find($userId, $url);

                if ($existing) {
                    return ['bookmark' => $existing, 'created' => false];
                }

                $bookmark = EventObject::create(array_merge([
                    'title' => $url,
                    'time' => now(),
                ], $attributes, [
                    'user_id' => $userId,
                    'concept' => 'bookmark',
                    'type' => 'fetch_webpage',
                    'url' => $url,
                    'metadata' => array_merge($metadata, ['canonical_url' => $canonical]),
                ]));

                return ['bookmark' => $bookmark, 'created' => true];
            });
    }

    /**
     * Bookmarks created before canonical_url existed: match by canonicalising
     * their stored URL (narrowed by host) and backfill the identity.
     *
     * @param  list<string>  $canonicals
     * @return array<string, EventObject>
     */
    private function findLegacy(string $userId, array $canonicals): array
    {
        $hosts = collect($canonicals)
            ->map(fn (string $canonical): ?string => parse_url($canonical, PHP_URL_HOST) ?: null)
            ->filter()
            ->unique()
            ->values();

        if ($hosts->isEmpty()) {
            return [];
        }

        $wanted = array_flip($canonicals);
        $found = [];

        $this->bookmarks($userId)
            ->whereNull('metadata->canonical_url')
            ->where(function (Builder $query) use ($hosts): void {
                foreach ($hosts as $host) {
                    $query->orWhere('url', 'ilike', '%://' . $host . '%');
                }
            })
            ->orderBy('created_at')
            ->get()
            ->each(function (EventObject $bookmark) use ($wanted, &$found): void {
                $canonical = UrlCanonicalizer::canonicalize((string) $bookmark->url);

                if (! isset($wanted[$canonical]) || isset($found[$canonical])) {
                    return;
                }

                FetchMetadata::merge($bookmark, ['canonical_url' => $canonical]);
                $found[$canonical] = $bookmark;
            });

        return $found;
    }

    /**
     * @return Builder<EventObject>
     */
    private function bookmarks(string $userId): Builder
    {
        return EventObject::query()
            ->where('user_id', $userId)
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage');
    }

    private function lockKey(string $userId, string $canonical): string
    {
        return 'fetch-bookmark:' . $userId . ':' . sha1($canonical);
    }
}
