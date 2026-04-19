<?php

namespace App\Mcp\Tools;

use App\Http\Resources\EventResource;
use App\Services\Mobile\EventFeed;
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

        if (! $service) {
            return Response::error('The "service" parameter is required.');
        }

        if ($user->integrations()->count() === 0) {
            return Response::error('No integrations found for this user.');
        }

        $result = app(EventFeed::class)->filter(
            $user,
            $service,
            $request->get('action'),
            $request->get('from_date'),
            $request->get('to_date'),
            (int) $request->get('limit', EventFeed::LIMIT_DEFAULT),
        );

        $payload = [
            'service' => $result['service'],
            'action' => $result['action'],
            'total_count' => $result['total_count'],
            'returned_count' => $result['returned_count'],
            'events' => EventResource::collection($result['events'])->resolve(request()),
        ];

        return Response::text(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
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
