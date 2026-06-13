<?php

namespace App\Services\Fetch;

use App\Exceptions\UnsafeUrlException;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\User;

/**
 * Shared bookmark-creation logic used by both the public fetch API
 * (FetchApiController::bookmarkUrl) and the mobile share-extension endpoint
 * (Api\V1\Mobile\BookmarksController). Keeping it here guarantees dedupe and
 * fetch-job dispatch behaviour stays identical across both surfaces.
 */
class BookmarkUrlService
{
    public function __construct(
        protected UrlSafetyValidator $urlSafety,
        protected FetchIntegrationResolver $integrationResolver,
    ) {}

    /**
     * Create (or resolve an existing) bookmark for the user and optionally
     * dispatch a fetch job.
     *
     * @return array{state: string, bookmark: EventObject, job_dispatched: bool, created: bool}
     *
     * @throws UnsafeUrlException when the URL fails the safety validator.
     */
    public function bookmark(
        User $user,
        string $url,
        bool $fetchImmediately = true,
        bool $forceRefresh = false,
        string $fetchMode = 'once',
    ): array {
        $this->urlSafety->validate($url);

        $domain = parse_url($url, PHP_URL_HOST);

        $existingBookmark = EventObject::where('user_id', $user->id)
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where('url', $url)
            ->first();

        $integration = $this->integrationResolver->resolve($user);

        if ($existingBookmark) {
            $jobDispatched = false;
            if ($forceRefresh && $fetchImmediately) {
                FetchSingleUrl::dispatch($integration, $existingBookmark->id, $existingBookmark->url, true);
                $jobDispatched = true;
            }

            return [
                'state' => $jobDispatched ? 'refreshed' : 'already_exists',
                'bookmark' => $existingBookmark,
                'job_dispatched' => $jobDispatched,
                'created' => false,
            ];
        }

        $bookmark = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => $url, // Will be updated with the real title after fetch
            'url' => $url,
            'time' => now(),
            'metadata' => [
                'domain' => $domain,
                'fetch_integration_id' => $integration?->id,
                'subscription_source' => 'api',
                'fetch_mode' => $fetchMode,
                'enabled' => true,
                'subscribed_at' => now()->toISOString(),
                'fetch_count' => 0,
            ],
        ]);

        $jobDispatched = false;
        if ($fetchImmediately && $integration) {
            FetchSingleUrl::dispatch($integration, $bookmark->id, $bookmark->url);
            $jobDispatched = true;
        }

        return [
            'state' => $jobDispatched ? 'queued' : 'pending_no_fetch',
            'bookmark' => $bookmark,
            'job_dispatched' => $jobDispatched,
            'created' => true,
        ];
    }
}
