<?php

namespace App\Mcp\Tools;

use App\Mcp\Helpers\MetricIdentifierMap;
use App\Services\Mobile\MetricTrendService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetMetricTrendTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Retrieve daily metric values over a date range with baseline comparison.
        Returns per-day values, vs_baseline_pct, anomaly flags, and summary statistics.
        Accepts flexible identifiers: "oura.had_sleep_score.percent", "oura.sleep_score", etc.
        The "had_" prefix and value_unit can be omitted when unambiguous.
        Use get-baselines to discover available metrics.
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

        $metricId = $request->get('metric');
        $service = app(MetricTrendService::class);

        if (! $service->resolve($metricId, $user)) {
            $scope = explode('.', $metricId)[0] ?? '';

            return Response::error("Unknown metric identifier: {$metricId}. ".MetricIdentifierMap::availableForService($scope, $user));
        }

        $result = $service->trend(
            $user,
            $metricId,
            $request->get('from', '30_days_ago'),
            $request->get('to', 'today'),
        );

        if ($result === null) {
            return Response::error('Invalid date range.');
        }

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()
                ->description('Metric identifier in dot notation (e.g. "oura.sleep_score", "oura.had_sleep_score.percent"). The "had_" prefix and value_unit can be omitted when unambiguous. Use get-baselines to discover available metrics.')
                ->required(),

            'from' => $schema->string()
                ->description('Start date. ISO format, relative ("yesterday", "7_days_ago"), or range keyword ("last_7_days", "this_week", "last_month"). Defaults to "30_days_ago".')
                ->default('30_days_ago'),

            'to' => $schema->string()
                ->description('End date. ISO format or relative. Defaults to "today".')
                ->default('today'),
        ];
    }
}
