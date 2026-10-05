<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CapturedContentExtractionException;
use App\Exceptions\UnsafeUrlException;
use App\Http\Controllers\Controller;
use App\Services\Fetch\CaptureBookmarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapturedBookmarksController extends Controller
{
    public function __construct(protected CaptureBookmarkService $captures) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'html' => ['required', 'string', 'max:5242880'],
            'title' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->captures->capture(
                $request->user(),
                $validated['url'],
                $validated['html'],
                $validated['title'] ?? null,
            );
        } catch (UnsafeUrlException) {
            return response()->json([
                'message' => 'This URL is not allowed.',
                'errors' => ['url' => ['This URL is not allowed.']],
            ], 422);
        } catch (CapturedContentExtractionException $exception) {
            return response()->json([
                'message' => 'Spark could not extract readable content from this page.',
                'errors' => ['html' => [$exception->getMessage()]],
            ], 422);
        }

        $bookmark = $result['bookmark'];

        return response()->json([
            'state' => $result['state'],
            'bookmark' => [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
                'title' => $bookmark->title,
            ],
        ], $result['created'] ? 201 : 200);
    }
}
