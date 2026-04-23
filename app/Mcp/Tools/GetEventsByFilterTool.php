<?php

namespace App\Mcp\Tools;

use App\Http\Resources\EventResource;
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
class GetEventsByFilterTool extends Tool
{
    use DateParser;

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Filter events precisely by service, action, and date range.
        Unlike semantic search, this returns exact matches — use for specific queries
        like "all Monzo transactions this week" or "Oura sleep scores last 7 days".
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

        $service = $request->get('service');
        $action = $request->get('action');
        $from = $request->get('from_date');
        $to = $request->get('to_date');
        $limit = min((int) ($request->get('limit', 50)), 100);

        if (! $service) {
            return Response::error('The "service" parameter is required.');
        }

        // Get user's integration IDs for authorization
        $integrationIds = $user->integrations()->pluck('id')->all();

        if (empty($integrationIds)) {
            return Response::error('No integrations found for this user.');
        }

        $query = Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->where('service', $service)
            ->with(['actor', 'target', 'blocks', 'tags']);

        if ($action) {
            $query->where('action', $action);
        }

        // Apply date range
        $dateRange = $this->parseDateRange($from, $to);
        if ($dateRange) {
            $query->whereBetween('time', $dateRange);
        }

        // Get total count before limiting
        $totalCount = $query->count();

        $events = $query->orderBy('time', 'desc')
            ->limit($limit)
            ->get();

        $result = [
            'service' => $service,
            'action' => $action,
            'total_count' => $totalCount,
            'returned_count' => $events->count(),
            'events' => EventResource::collection($events)->resolve(request()),
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
            'service' => $schema->string()
                ->description('Service to filter by (e.g. "oura", "apple_health", "monzo", "spotify").')
                ->required(),

            'action' => $schema->string()
                ->description('Action type to filter by (e.g. "had_sleep_score", "listened_to"). Optional — omit to get all actions for the service.'),

            'from_date' => $schema->string()
                ->description('Start date. ISO format, relative, or range keyword. Defaults to last 30 days if omitted.'),

            'to_date' => $schema->string()
                ->description('End date. ISO format or relative. Defaults to today.'),

            'limit' => $schema->integer()
                ->description('Maximum events to return (1-100). Defaults to 50.')
                ->default(50),
        ];
    }
}
