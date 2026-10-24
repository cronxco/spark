<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactIntegrationResource;
use App\Http\Resources\Compact\CompactMetricResource;
use App\Http\Resources\Compact\CompactObjectResource;
use App\Services\Mobile\SearchContract;
use App\Services\Mobile\SearchDispatcher;
use App\Services\Search\LegacyAliasUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SearchController extends Controller
{
    public function __construct(protected SearchDispatcher $dispatcher, protected SearchContract $contract) {}

    /**
     * GET /api/v1/mobile/search?contract_version=1&query=...&mode=default
     *
     * With `contract_version=1` the request and response follow
     * {@see SearchContract}: canonical `query` and singular modes only, a flat
     * `results` list and an `execution` block. Without it the pre-v1 shape is
     * served for shipped builds (see {@see self::legacy()}).
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->query->has('contract_version')) {
            return $this->contractV1($request);
        }

        return $this->legacy($request);
    }

    protected function contractV1(Request $request): JsonResponse
    {
        if ((string) $request->query('contract_version') !== (string) SearchContract::VERSION) {
            return response()->json([
                'message' => 'Unsupported search contract version.',
                'supported_versions' => [SearchContract::VERSION],
            ], 422);
        }

        $data = $request->validate([
            'query' => ['present', 'nullable', 'string', 'max:500'],
            'mode' => ['nullable', 'string', Rule::in(SearchDispatcher::MODES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . SearchDispatcher::MAX_LIMIT],
        ]);

        $mode = $data['mode'] ?? 'default';
        $limit = (int) ($data['limit'] ?? SearchDispatcher::DEFAULT_LIMIT);

        $results = $this->dispatcher->search($request->user(), $mode, (string) ($data['query'] ?? ''), $limit, allFamilies: true);

        return response()->json($this->contract->present($results, $request, $limit));
    }

    /**
     * Pre-v1 search: `q`, plural mode aliases and four grouped collections.
     * `mode` ∈ default|semantic|tag|metric|integration. The plural spellings
     * shipped iOS builds send (`tags`, `metrics`, `integrations`) are accepted
     * as aliases. Unknown modes produce 422 so iOS clients never silently fall
     * through to a less-targeted query. Alias use is counted (S3) so it can be
     * removed once no shipped build sends it.
     */
    protected function legacy(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'default');
        if (isset(SearchDispatcher::MODE_ALIASES[$mode])) {
            LegacyAliasUsage::record('plural_mode');
        }
        $mode = SearchDispatcher::MODE_ALIASES[$mode] ?? $mode;
        LegacyAliasUsage::record('q');
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
