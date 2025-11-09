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
            'fetch_mode' => ['string', 'in:once,recurring'],
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
        $fetchMode = $validated['fetch_mode'] ?? 'once'; // Default to one-time fetch for API bookmarks

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

        // Get user's Fetch integration to store in metadata
        $integration = $request->user()->integrations()
            ->where('service', 'fetch')
            ->first();

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
                'fetch_integration_id' => $integration?->id,
                'subscription_source' => 'api',
                'fetch_mode' => $fetchMode, // Default 'once', but can be 'recurring'
                'enabled' => true,
                'subscribed_at' => now()->toISOString(),
                'fetch_count' => 0,
            ],
        ]);

        // Dispatch fetch job if requested
        $jobDispatched = false;
        if ($fetchImmediately && $integration) {
            FetchSingleUrl::dispatch($integration, $bookmark->id, $bookmark->url);
            $jobDispatched = true;
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
