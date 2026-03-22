<?php

namespace App\Mcp\Tools;

use App\Mcp\Helpers\MetricIdentifierMap;
use App\Models\MetricStatistic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetBaselinesTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Retrieve baseline statistics for one or more metrics.
        Returns mean, stddev, min, max, normal bounds, and sample size.
        Accepts flexible identifiers: "oura.sleep_score", "oura.had_sleep_score.percent", etc.
        Omit metrics to get all available baselines — useful for discovering what metrics exist.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $metricIds = $request->get('metrics');

        if ($metricIds && ! is_array($metricIds)) {
            $metricIds = [$metricIds];
        }

        $baselines = [];

        if ($metricIds) {
            $resolved = MetricIdentifierMap::resolveMany($metricIds, $user);

            if (empty($resolved)) {
                $available = implode(', ', array_slice(MetricIdentifierMap::availableIdentifiers($user), 0, 20));

                return Response::error("No valid metric identifiers provided. Available: {$available}");
            }

            foreach ($resolved as $identifier => $statistic) {
                $baselines[$statistic->getIdentifier()] = $this->formatBaseline($statistic);
            }
        } else {
            $statistics = MetricStatistic::where('user_id', $user->id)
                ->orderBy('service')
                ->orderBy('action')
                ->get();

            foreach ($statistics as $statistic) {
                $baselines[$statistic->getIdentifier()] = $this->formatBaseline($statistic);
            }
        }

        $result = [
            'baselines' => $baselines,
            'count' => count($baselines),
        ];

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'metrics' => $schema->array()
                ->items($schema->string())
                ->description('Array of metric identifiers (e.g. ["oura.sleep_score", "apple_health.step_count"]). The "had_" prefix and value_unit can be omitted. Omit entirely to get all available baselines.'),
        ];
    }

    /**
     * Format a MetricStatistic into baseline data.
     */
    protected function formatBaseline(MetricStatistic $statistic): array
    {
        if (! $statistic->hasValidStatistics()) {
            return [
                'status' => 'insufficient_data',
                'unit' => $statistic->value_unit,
                'sample_days' => $statistic->event_count,
                'display_name' => $statistic->getDisplayName(),
            ];
        }

        return [
            'status' => 'available',
            'unit' => $statistic->value_unit,
            'mean' => round($statistic->mean_value, 2),
            'stddev' => round($statistic->stddev_value, 2),
            'min' => round($statistic->min_value, 2),
            'max' => round($statistic->max_value, 2),
            'normal_lower' => round($statistic->normal_lower_bound, 2),
            'normal_upper' => round($statistic->normal_upper_bound, 2),
            'sample_days' => $statistic->event_count,
            'display_name' => $statistic->getDisplayName(),
        ];
    }
}
