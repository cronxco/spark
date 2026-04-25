<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactIntegrationResource;
use App\Http\Resources\Compact\CompactMetricResource;
use App\Http\Resources\Compact\CompactObjectResource;
use App\Services\Mobile\SearchDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(protected SearchDispatcher $dispatcher) {}

    /**
     * GET /api/v1/mobile/search?q=...&mode=default
     *
     * `mode` ∈ default|semantic|tag|metric|integration. Unknown modes produce
     * 422 so iOS clients never silently fall through to a less-targeted query.
     */
    public function index(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'default');
        $query = (string) $request->query('q', '');
        $limit = (int) $request->query('limit', SearchDispatcher::DEFAULT_LIMIT);

        if (! in_array($mode, SearchDispatcher::MODES, true)) {
            return response()->json([
                'message' => 'Invalid search mode.',
                'modes' => SearchDispatcher::MODES,
            ], 422);
        }

        $results = $this->dispatcher->search($request->user(), $mode, $query, $limit);

        return response()->json([
            'mode' => $results['mode'],
            'query' => $results['query'],
            'events' => CompactEventResource::collection($results['events'])->resolve($request),
            'objects' => CompactObjectResource::collection($results['objects'])->resolve($request),
            'integrations' => CompactIntegrationResource::collection($results['integrations'])->resolve($request),
            'metrics' => CompactMetricResource::collection($results['metrics'])->resolve($request),
        ]);
    }
}
