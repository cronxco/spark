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
        protected BookmarkCreator $bookmarks,
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

        $integration = $this->integrationResolver->resolve($user);

        $result = $this->bookmarks->firstOrCreate($user->id, $url, ['title' => $url], [
            'domain' => $domain,
            'fetch_integration_id' => $integration?->id,
            'subscription_source' => 'api',
            'fetch_mode' => $fetchMode,
            'enabled' => true,
            'subscribed_at' => now()->toISOString(),
            'fetch_count' => 0,
        ]);
        $bookmark = $result['bookmark'];

        if (! $result['created']) {
            $jobDispatched = false;
            if ($forceRefresh && $fetchImmediately) {
                FetchSingleUrl::dispatch($integration, $bookmark->id, $bookmark->url, true);
                $jobDispatched = true;
            }

            return [
                'state' => $jobDispatched ? 'refreshed' : 'already_exists',
                'bookmark' => $bookmark,
                'job_dispatched' => $jobDispatched,
                'created' => false,
            ];
        }

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
