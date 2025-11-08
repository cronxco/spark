<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FetchApiController extends Controller
{
    /**
     * Bookmark a URL for fetching.
     */
    public function bookmarkUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'url' => ['required', 'url', 'max:2048'],
            'fetch_immediately' => ['boolean'],
            'force_refresh' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $url = $validated['url'];
        $fetchImmediately = $validated['fetch_immediately'] ?? true;
        $forceRefresh = $validated['force_refresh'] ?? false;

        // Parse domain from URL
        $domain = parse_url($url, PHP_URL_HOST);

        // Check for existing bookmark
        $existingBookmark = EventObject::where('user_id', $request->user()->id)
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where('url', $url)
            ->first();

        if ($existingBookmark) {
            // If force refresh is requested, dispatch job for existing bookmark
            $jobDispatched = false;
            if ($forceRefresh && $fetchImmediately) {
                $integration = $request->user()->integrations()
                    ->where('service', 'fetch')
                    ->first();

                if ($integration) {
                    FetchSingleUrl::dispatch($integration, $existingBookmark->id, $existingBookmark->url, true);
                    $jobDispatched = true;
                }
            }

            // Return existing bookmark
            return response()->json([
                'success' => true,
                'bookmark' => [
                    'id' => $existingBookmark->id,
                    'url' => $existingBookmark->url,
                    'title' => $existingBookmark->title,
                    'status' => $existingBookmark->metadata['enabled'] ?? true ? 'active' : 'disabled',
                    'created_at' => $existingBookmark->created_at->toISOString(),
                ],
                'job_dispatched' => $jobDispatched,
                'message' => $jobDispatched ? 'Force refresh dispatched' : 'Bookmark already exists',
            ]);
        }

        // Create new bookmark
        $bookmark = EventObject::create([
            'user_id' => $request->user()->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => $url, // Will be updated with actual title after fetch
            'url' => $url,
            'time' => now(),
            'metadata' => [
                'domain' => $domain,
                'subscription_source' => 'api',
                'enabled' => true,
                'subscribed_at' => now()->toISOString(),
            ],
        ]);

        // Dispatch fetch job if requested
        $jobDispatched = false;
        if ($fetchImmediately) {
            // Get user's Fetch integration
            $integration = $request->user()->integrations()
                ->where('service', 'fetch')
                ->first();

            if ($integration) {
                FetchSingleUrl::dispatch($integration, $bookmark->id, $bookmark->url);
                $jobDispatched = true;
            }
        }

        return response()->json([
            'success' => true,
            'bookmark' => [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
                'title' => $bookmark->title,
                'status' => 'pending',
                'created_at' => $bookmark->created_at->toISOString(),
            ],
            'job_dispatched' => $jobDispatched,
        ]);
    }
}
