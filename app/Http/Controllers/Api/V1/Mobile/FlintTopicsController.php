<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\FlintTopicService;
use App\Services\FlintTopicTaskService;
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . CollectionCursorPage::MAX_LIMIT],
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

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:strategic,thematic,tactical'],
        ]);
        $topic = $this->topics->update($request->user(), $id, $validated);

        return $topic
            ? response()->json(['data' => $this->topics->detail($request->user(), $id)])
            : response()->json(['message' => 'Thread not found.'], 404);
    }

    public function storeTask(Request $request, string $id, FlintTopicTaskService $tasks): JsonResponse
    {
        $validated = $request->validate([
            'client_mutation_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:20000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'review_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $task = $tasks->create($request->user(), $id, $validated);

        return $task
            ? response()->json(['data' => $task], 201)
            : response()->json(['message' => 'Thread not found.'], 404);
    }

    public function updateTask(Request $request, string $id, string $taskId, FlintTopicTaskService $tasks): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'review_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'completed' => ['sometimes', 'required', 'boolean'],
        ]);
        $task = $tasks->update($request->user(), $id, $taskId, $validated, $request->header('If-Match'));

        return $task
            ? response()->json(['data' => $task])
            : response()->json(['message' => 'Task not found.'], 404);
    }
}
