<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactMetricResource;
use App\Mcp\Helpers\MetricIdentifierMap;
use App\Models\MetricStatistic;
use App\Services\Mobile\MetricTrendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __construct(protected MetricTrendService $trendService) {}

    /**
     * GET /api/v1/mobile/metrics
     *
     * Returns all metric identifiers and metadata for the authenticated user.
     * Used by the client to build a dynamic metrics catalogue.
     */
    public function index(Request $request): JsonResponse
    {
        $metrics = MetricStatistic::where('user_id', $request->user()->id)
            ->orderBy('service')
            ->orderBy('action')
            ->get();

        return response()->json(
            CompactMetricResource::collection($metrics)->resolve($request),
        );
    }

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

        [$from, $to] = $this->dateRangeForRequest($request);

        $payload = $this->trendService->trend($user, $metric, $from, $to);

        if ($payload === null) {
            return response()->json(['message' => 'Invalid date range.'], 422);
        }

        return response()->json($payload);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRangeForRequest(Request $request): array
    {
        $range = $request->query('range');

        if (! is_string($range)) {
            return [
                $request->query('from', '30_days_ago'),
                $request->query('to', 'today'),
            ];
        }

        return match ($range) {
            '7d' => ['6_days_ago', 'today'],
            '30d' => ['29_days_ago', 'today'],
            '90d' => ['89_days_ago', 'today'],
            '1y' => ['364_days_ago', 'today'],
            default => [$range, $range],
        };
    }
}
