<?php

namespace App\Services\Fetch;

use App\Exceptions\CapturedContentExtractionException;
use App\Integrations\Fetch\ContentExtractor;
use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Jobs\Fetch\ExpandLinkListJob;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Fetch\Assessment\ListPageDetector;
use App\Services\Fetch\Expansion\ListItem;

class CaptureBookmarkService
{
    public function __construct(
        protected UrlSafetyValidator $urlSafety,
        protected BookmarkUrlService $bookmarks,
        protected FetchIntegrationResolver $integrationResolver,
        protected BookmarkCreator $bookmarkCreator,
        protected ListPageDetector $listDetector,
    ) {}

    /**
     * Store HTML supplied by a trusted caller and hand it to the same revision
     * and enrichment pipeline as a normal fetch.
     *
     * @return array{state: string, bookmark: EventObject, created: bool, items_found?: int}
     */
    public function capture(
        User $user,
        string $url,
        string $html,
        ?string $capturedTitle = null,
        string $source = 'browser_extension',
        string $captureMethod = 'rendered_dom',
    ): array {
        $this->urlSafety->validate($url);

        // The caller has already crossed any login/paywall and supplied the page.
        // Keep structural validation, but do not reject hidden access-control
        // markup that remains in the rendered DOM alongside the full article.
        $parsed = ContentExtractor::parse($html, $url);
        $extraction = ContentExtractor::validateParsed($parsed, $html, $url, $user->id, detectAccessBarriers: false);

        if ($listCapture = $this->captureList($user, $url, $html, $parsed, $extraction, $capturedTitle, $source, $captureMethod)) {
            return $listCapture;
        }

        if (! $extraction['success']) {
            throw new CapturedContentExtractionException($extraction['reason'] ?? 'Content extraction failed');
        }

        $extracted = $extraction['data'];
        if ($capturedTitle !== null && trim($capturedTitle) !== '') {
            $extracted['title'] = trim($capturedTitle);
        }

        $result = $this->bookmarks->bookmark(
            $user,
            $url,
            fetchImmediately: false,
            fetchMode: 'once',
        );

        $bookmark = $result['bookmark'];
        $metadata = $bookmark->metadata ?? [];
        $bookmark->update([
            'title' => $extracted['title'],
            'metadata' => array_merge($metadata, [
                'subscription_source' => $source,
                'last_capture_at' => now()->toISOString(),
                'last_capture_method' => $captureMethod,
            ]),
        ]);

        $contentHash = ContentExtractor::generateHash($extracted['text_content']);
        $integration = $this->integrationResolver->resolve($user);

        ProcessFetchedContent::dispatch(
            $integration,
            $bookmark->fresh(),
            $extracted,
            $contentHash,
        );

        return [
            'state' => $result['created'] ? 'captured' : 'recaptured',
            'bookmark' => $bookmark->fresh(),
            'created' => $result['created'],
        ];
    }

    /**
     * A captured page that is a list of articles is bookmarked as fetched and
     * its articles are expanded, instead of being stored as one article.
     *
     * @param  array{success: bool, reason: ?string, data: ?array}  $parsed
     * @param  array{success: bool, reason: ?string}  $validation
     * @return array{state: string, bookmark: EventObject, created: bool, items_found: int}|null
     */
    private function captureList(
        User $user,
        string $url,
        string $html,
        array $parsed,
        array $validation,
        ?string $capturedTitle,
        string $source,
        string $captureMethod,
    ): ?array {
        if (! ListPageDetector::isEnabled()) {
            return null;
        }

        $capturedTitle = $capturedTitle !== null && trim($capturedTitle) !== '' ? trim($capturedTitle) : null;
        $probe = $this->bookmarkCreator->find($user->id, $url)
            ?? new EventObject(['user_id' => $user->id, 'url' => $url, 'metadata' => ['subscription_source' => $source]]);

        $assessment = $this->listDetector->detect($probe, $html, $url, $parsed, $validation, $capturedTitle);

        if ($assessment === null) {
            return null;
        }

        $result = $this->bookmarks->bookmark($user, $url, fetchImmediately: false, fetchMode: 'once');
        $bookmark = $result['bookmark'];
        $title = $capturedTitle ?? trim((string) ($parsed['data']['title'] ?? ''));
        $titleAttributes = $title !== '' && $this->bookmarkCreator->titleIsAvailable($bookmark, $title) ? ['title' => $title] : [];
        $memo = $probe->metadata['list_detection'] ?? [];

        FetchMetadata::mutate($bookmark, function (array $metadata) use ($source, $captureMethod, $memo, $result): array {
            $once = ($metadata['fetch_mode'] ?? 'recurring') === 'once';

            return array_merge($metadata, [
                'subscription_source' => $result['created'] ? $source : ($metadata['subscription_source'] ?? $source),
                'last_capture_at' => now()->toISOString(),
                'last_capture_method' => $captureMethod,
                'last_checked_at' => now()->toIso8601String(),
                'fetch_count' => max(1, (int) ($metadata['fetch_count'] ?? 0)),
                'last_error' => null,
                'pipeline_status' => 'complete',
                'list_detection' => array_merge($metadata['list_detection'] ?? [], $memo, ['expansion_status' => 'pending']),
            ], $once ? ['enabled' => false] : []);
        }, $titleAttributes);

        ExpandLinkListJob::dispatch(
            $this->integrationResolver->resolve($user),
            (string) $bookmark->id,
            array_map(fn ($candidate): array => ListItem::fromCandidate($candidate)->toArray(), $assessment->acceptedItems),
            $assessment->toArray(),
        );

        return [
            'state' => 'list_expanded',
            'bookmark' => $bookmark->fresh(),
            'created' => $result['created'],
            'items_found' => count($assessment->acceptedItems),
        ];
    }
}
