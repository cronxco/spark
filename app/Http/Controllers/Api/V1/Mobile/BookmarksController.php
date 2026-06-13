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
     * POST /api/v1/mobile/bookmarks
     *
     * Bookmarks a URL shared from the iOS share extension. Delegates to the
     * same service as FetchApiController::bookmarkUrl so dedupe and fetch-job
     * dispatch behaviour is identical. The client only needs a 2xx.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        try {
            $result = $this->bookmarks->bookmark($request->user(), $validated['url']);
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
        ], $result['created'] ? 201 : 200);
    }
}
