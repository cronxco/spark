<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Mcp\Helpers\MetricIdentifierMap;
use App\Services\Mobile\MetricTrendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __construct(protected MetricTrendService $trendService) {}

    /**
     * GET /api/v1/mobile/metrics/{metric}
     *
     * Returns the full trend payload (per-day values, baseline, summary)
     * — the shape already matches the MCP tool exactly.
     */
    public function show(Request $request, string $metric): JsonResponse
    {
        $user = $request->user();

        if (! $this->trendService->resolve($metric, $user)) {
            $scope = explode('.', $metric)[0] ?? '';

            return response()->json([
                'message' => "Unknown metric identifier: {$metric}.",
                'hint' => MetricIdentifierMap::availableForService($scope, $user),
            ], 404);
        }

        $payload = $this->trendService->trend(
            $user,
            $metric,
            $request->query('from', '30_days_ago'),
            $request->query('to', 'today'),
        );

        if ($payload === null) {
            return response()->json(['message' => 'Invalid date range.'], 422);
        }

        return response()->json($payload);
    }
}
