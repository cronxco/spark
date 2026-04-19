<?php

namespace App\Mcp\Tools;

use App\Mcp\Helpers\DateParser;
use App\Mcp\Helpers\MetricIdentifierMap;
use App\Models\MetricTrend;
use App\Services\Mobile\AnomalyAcknowledgement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class AcknowledgeAnomalyTool extends Tool
{
    use DateParser;

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Acknowledge a metric anomaly so it no longer appears in day summaries.
        Optionally add a note explaining why, and suppress future alerts for this metric
        until a specified date. This is the only write operation — all other tools are read-only.
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
        $dateInput = $request->get('date', 'today');
        $note = $request->get('note');
        $suppressUntil = $request->get('suppress_until');

        $statistic = MetricIdentifierMap::resolve($metricId, $user);
        if (! $statistic) {
            $service = explode('.', $metricId)[0] ?? '';

            return Response::error("Unknown metric identifier: {$metricId}. " . MetricIdentifierMap::availableForService($service, $user));
        }

        $date = $this->parseDate($dateInput);
        if (! $date) {
            return Response::error('Invalid date format.');
        }

        // Find unacknowledged anomalies for this metric on this date
        $anomalies = MetricTrend::where('metric_statistic_id', $statistic->id)
            ->anomalies()
            ->unacknowledged()
            ->whereDate('detected_at', $date)
            ->get();

        if ($anomalies->isEmpty()) {
            return Response::error("No unacknowledged anomalies found for {$statistic->getIdentifier()} on {$date->toDateString()}.");
        }

        $service = app(AnomalyAcknowledgement::class);

        $metadata = [];
        if ($note) {
            $metadata['acknowledgement_note'] = $note;
        }
        if ($suppressUntil) {
            $suppressDate = $this->parseDate($suppressUntil);
            if ($suppressDate) {
                $metadata['suppress_until'] = $suppressDate->toDateString();
            }
        }

        $acknowledged = 0;
        foreach ($anomalies as $anomaly) {
            if ($service->acknowledge($user, (string) $anomaly->id, $metadata)) {
                $acknowledged++;
            }
        }

        $result = [
            'metric' => $statistic->getIdentifier(),
            'date' => $date->toDateString(),
            'anomalies_acknowledged' => $acknowledged,
        ];

        if ($note) {
            $result['note'] = $note;
        }

        if ($suppressUntil) {
            $result['suppress_until'] = $this->parseDate($suppressUntil)?->toDateString();
        }

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
            'metric' => $schema->string()
                ->description('Metric identifier (e.g. "oura.sleep_score", "oura.had_sleep_score.percent"). The "had_" prefix and value_unit can be omitted.')
                ->required(),

            'date' => $schema->string()
                ->description('Date of the anomaly to acknowledge. ISO format or relative. Defaults to "today".')
                ->default('today'),

            'note' => $schema->string()
                ->description('Optional note explaining why this anomaly is being acknowledged (e.g. "Was sick", "Travel day").'),

            'suppress_until' => $schema->string()
                ->description('Optional date until which future anomalies for this metric should be suppressed. ISO format or relative.'),
        ];
    }
}
