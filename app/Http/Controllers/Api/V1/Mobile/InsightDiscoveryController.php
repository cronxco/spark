<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MetricStatistic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only agent-friendly insight discovery payloads for mobile clients. */
class InsightDiscoveryController extends Controller
{
    public function baselines(Request $request): JsonResponse
    {
        $metrics = MetricStatistic::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('service')
            ->orderBy('action')
            ->get()
            ->map(fn (MetricStatistic $metric): array => [
                'identifier' => $metric->getIdentifier(),
                'display_name' => $metric->getDisplayName(),
                'mean' => $metric->mean,
                'stddev' => $metric->stddev,
                'lower_bound' => $metric->lower_bound,
                'upper_bound' => $metric->upper_bound,
                'window_days' => $metric->baseline_window_days ?? MetricStatistic::DEFAULT_WINDOW_DAYS,
                'updated_at' => $metric->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $metrics]);
    }
}
