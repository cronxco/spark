<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Exceptions\UnsafeUrlException;
use App\Http\Controllers\Controller;
use App\Services\Fetch\BookmarkUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookmarksController extends Controller
{
    public function __construct(protected BookmarkUrlService $bookmarks) {}

    /**
     * POST /api/v1/bookmarks and /api/v1/mobile/bookmarks
     *
     * Bookmarks a URL, from the iOS share extension or a bookmark API token.
     * The optional fetch flags carry over from the retired /api/fetch/bookmarks
     * route; the share extension sends none and only needs a 2xx.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'fetch_immediately' => ['sometimes', 'boolean'],
            'force_refresh' => ['sometimes', 'boolean'],
            'fetch_mode' => ['sometimes', 'string', 'in:once,recurring'],
        ]);

        try {
            $result = $this->bookmarks->bookmark(
                $request->user(),
                $validated['url'],
                $validated['fetch_immediately'] ?? true,
                $validated['force_refresh'] ?? false,
                $validated['fetch_mode'] ?? 'once',
            );
        } catch (UnsafeUrlException $e) {
            return response()->json(['message' => 'This URL is not allowed.'], 422);
        }

        $bookmark = $result['bookmark'];

        return response()->json([
            'state' => $result['state'],
            'bookmark' => [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
            ],
            'job_dispatched' => $result['job_dispatched'],
        ], $result['created'] ? 201 : 200);
    }
}
