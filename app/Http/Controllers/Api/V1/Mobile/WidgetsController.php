<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\WidgetTodayResource;
use App\Mcp\Helpers\MetricIdentifierMap;
use App\Services\Mobile\MetricTrendService;
use App\Services\Mobile\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetsController extends Controller
{
    public function __construct(
        protected WidgetService $widgets,
        protected MetricTrendService $trendService,
    ) {}

    /**
     * GET /api/v1/mobile/widgets/today
     *
     * Compact briefing shape (headline + a handful of metrics + the user's
     * next upcoming event). Payload is capped at ~4 KB to satisfy WidgetKit.
     */
    public function today(Request $request): JsonResponse
    {
        $payload = $this->widgets->today($request->user());

        return response()->json(
            (new WidgetTodayResource($payload))->resolve($request),
        );
    }

    /**
     * GET /api/v1/mobile/widgets/metrics/{metric}
     *
     * Tiny payload: current value + unit + a 7-point sparkline.
     */
    public function metric(Request $request, string $metric): JsonResponse
    {
        $user = $request->user();

        if (! $this->trendService->resolve($metric, $user)) {
            $scope = explode('.', $metric)[0] ?? '';

            return response()->json([
                'message' => "Unknown metric identifier: {$metric}.",
                'hint' => MetricIdentifierMap::availableForService($scope, $user),
            ], 404);
        }

        $payload = $this->widgets->metric($user, $metric);

        if ($payload === null) {
            return response()->json(['message' => 'Metric trend unavailable.'], 422);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/v1/mobile/widgets/spend
     */
    public function spend(Request $request): JsonResponse
    {
        return response()->json($this->widgets->spend($request->user()));
    }
}
