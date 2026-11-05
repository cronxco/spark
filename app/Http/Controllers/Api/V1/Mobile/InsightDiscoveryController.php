<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MetricStatistic;
use App\Services\Metrics\BaselineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only agent-friendly insight discovery payloads for mobile clients. */
class InsightDiscoveryController extends Controller
{
    public function baselines(Request $request, BaselineService $baselines): JsonResponse
    {
        $metrics = MetricStatistic::query()
            ->where('user_id', $request->user()->id)
            ->with('baselines')
            ->orderBy('service')
            ->orderBy('action')
            ->get()
            ->filter(fn (MetricStatistic $metric): bool => $metric->hasValidStatistics())
            ->values()
            ->map(fn (MetricStatistic $metric): array => [
                'identifier' => $metric->getIdentifier(),
                'display_name' => $metric->getDisplayName(),
                // `mean`, `stddev`, `lower_bound` and `upper_bound` are the
                // centre, spread and 2-spread range of the metric's method.
                'mean' => (float) $metric->mean_value,
                'stddev' => (float) $metric->stddev_value,
                'lower_bound' => (float) $metric->normal_lower_bound,
                'upper_bound' => (float) $metric->normal_upper_bound,
                'window_days' => $metric->baseline_window_days ?? MetricStatistic::DEFAULT_WINDOW_DAYS,
                'baseline' => $baselines->describe($metric),
                'updated_at' => $metric->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $metrics]);
    }
}
