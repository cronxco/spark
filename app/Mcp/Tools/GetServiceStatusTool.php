<?php

namespace App\Mcp\Tools;

use App\Mcp\Helpers\DateParser;
use App\Models\Event;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetServiceStatusTool extends Tool
{
    use DateParser;

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Check sync status and data coverage for all services on a given date.
        Shows event count, last event time, distinct actions, and coverage notes
        for services with known sync lag (e.g. Apple Health).
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

        $dateInput = $request->get('date', 'today');
        $date = $this->parseDate($dateInput);

        if (! $date) {
            return Response::error('Invalid date format. Use ISO date (YYYY-MM-DD) or relative: "today", "yesterday", "tomorrow".');
        }

        $integrationIds = $user->integrations()->pluck('id')->all();

        if (empty($integrationIds)) {
            return Response::error('No integrations found for this user.');
        }

        $events = Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->whereBetween('time', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->get();

        $realTimeServices = ['apple_health'];

        $services = $events->groupBy('service')->map(function ($serviceEvents, $service) use ($realTimeServices) {
            $lastEvent = $serviceEvents->sortByDesc('time')->first();
            $actions = $serviceEvents->pluck('action')->unique()->sort()->values()->all();

            $status = [
                'event_count' => $serviceEvents->count(),
                'last_event_time' => $lastEvent->time->toISOString(),
                'actions' => $actions,
            ];

            // Coverage assessment for real-time services
            if (in_array($service, $realTimeServices)) {
                $hoursSinceLastEvent = $lastEvent->time->diffInHours(now());
                $status['coverage'] = $hoursSinceLastEvent > 2 ? 'partial' : 'complete';
                if ($hoursSinceLastEvent > 2) {
                    $status['coverage_note'] = "Last event was {$hoursSinceLastEvent}h ago — data may be incomplete.";
                }
            }

            return $status;
        })->all();

        $result = [
            'date' => $date->toDateString(),
            'total_events' => $events->count(),
            'services' => $services,
        ];

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
            'date' => $schema->string()
                ->description('Date to check. ISO format (YYYY-MM-DD) or relative: "today", "yesterday", "tomorrow". Defaults to "today".')
                ->default('today'),
        ];
    }
}
