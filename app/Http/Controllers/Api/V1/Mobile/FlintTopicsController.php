<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\FlintTopicService;
use App\Support\CollectionCursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlintTopicsController extends Controller
{
    public function __construct(private FlintTopicService $topics) {}

    /**
     * GET /api/v1/mobile/flint/topics?status=active&kind=thematic&limit=50&cursor=...
     *
     * Flint's long-lived strategic/thematic/tactical threads — the "running
     * threads" list on the Flint tab. Defaults to every status/kind.
     *
     * Paginated with the same cursor envelope as every other mobile
     * collection endpoint, even though the list is short today.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.CollectionCursorPage::MAX_LIMIT],
            'cursor' => ['nullable', 'string'],
        ]);
        $limit = (int) ($validated['limit'] ?? CollectionCursorPage::DEFAULT_LIMIT);

        $result = $this->topics->list($request->user(), $request->only(['status', 'kind']));
        [$page, $nextCursor, $hasMore] = CollectionCursorPage::paginate(
            collect($result['data']),
            $validated['cursor'] ?? null,
            $limit,
        );

        return response()->json([
            'data' => $page->all(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $topic = $this->topics->detail($request->user(), $id);

        return $topic
            ? response()->json(['data' => $topic])
            : response()->json(['message' => 'Thread not found.'], 404);
    }
}
