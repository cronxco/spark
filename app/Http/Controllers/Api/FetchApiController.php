<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UnsafeUrlException;
use App\Http\Controllers\Controller;
use App\Services\Fetch\BookmarkUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FetchApiController extends Controller
{
    public function __construct(
        protected BookmarkUrlService $bookmarks,
    ) {}

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

        try {
            $result = $this->bookmarks->bookmark(
                $request->user(),
                $validated['url'],
                $validated['fetch_immediately'] ?? true,
                $validated['force_refresh'] ?? false,
                $validated['fetch_mode'] ?? 'once', // Default to one-time fetch for API bookmarks
            );
        } catch (UnsafeUrlException $e) {
            return response()->json([
                'success' => false,
                'state' => 'rejected',
                'errors' => ['url' => ['This URL is not allowed.']],
            ], 422);
        }

        $bookmark = $result['bookmark'];
        $jobDispatched = $result['job_dispatched'];

        if (! $result['created']) {
            return response()->json([
                'success' => true,
                'state' => $result['state'],
                'bookmark' => [
                    'id' => $bookmark->id,
                    'url' => $bookmark->url,
                    'title' => $bookmark->title,
                    'status' => $bookmark->metadata['enabled'] ?? true ? 'active' : 'disabled',
                    'created_at' => $bookmark->created_at->toISOString(),
                ],
                'job_dispatched' => $jobDispatched,
                'message' => $jobDispatched ? 'Force refresh dispatched' : 'Bookmark already exists',
            ]);
        }

        return response()->json([
            'success' => true,
            'state' => $result['state'],
            'bookmark' => [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
                'title' => $bookmark->title,
                'status' => 'pending',
                'created_at' => $bookmark->created_at->toISOString(),
            ],
            'job_dispatched' => $jobDispatched,
            'message' => $jobDispatched
                ? 'Bookmark queued for fetching'
                : 'Bookmark saved; set fetch_immediately to queue a fetch',
        ]);
    }
}
