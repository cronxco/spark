<?php

namespace App\Services\Fetch;

use App\Exceptions\CapturedContentExtractionException;
use App\Integrations\Fetch\ContentExtractor;
use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Models\EventObject;
use App\Models\User;

class CaptureBookmarkService
{
    public function __construct(
        protected UrlSafetyValidator $urlSafety,
        protected BookmarkUrlService $bookmarks,
        protected FetchIntegrationResolver $integrationResolver,
    ) {}

    /**
     * Store HTML captured from the user's authenticated browser session and
     * hand it to the same revision and enrichment pipeline as a normal fetch.
     *
     * @return array{state: string, bookmark: EventObject, created: bool}
     */
    public function capture(User $user, string $url, string $html, ?string $capturedTitle = null): array
    {
        $this->urlSafety->validate($url);

        // The user has already crossed any login/paywall in their browser.
        // Keep structural validation, but do not reject hidden access-control
        // markup that remains in the rendered DOM alongside the full article.
        $extraction = ContentExtractor::extractCaptured($html, $url, $user->id);

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
                'subscription_source' => 'browser_extension',
                'last_capture_at' => now()->toISOString(),
                'last_capture_method' => 'rendered_dom',
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
}
