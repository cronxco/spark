<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\FlintTopicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlintTopicsController extends Controller
{
    public function __construct(private FlintTopicService $topics) {}

    /**
     * GET /api/v1/mobile/flint/topics?status=active&kind=thematic
     *
     * Flint's long-lived strategic/thematic/tactical threads — the "running
     * threads" list on the Flint tab. Defaults to every status/kind.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->topics->list($request->user(), $request->only(['status', 'kind']))
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $topic = $this->topics->detail($request->user(), $id);

        return $topic
            ? response()->json(['data' => $topic])
            : response()->json(['message' => 'Thread not found.'], 404);
    }
}
