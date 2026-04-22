<?php

namespace App\Mcp\Tools;

use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetEventTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Retrieve full details for a specific event by its UUID.
        Returns the complete event with actor, target, blocks, tags, and integration context.
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

        $eventId = $request->get('id');

        if (empty($eventId)) {
            return Response::error('Event ID is required.');
        }

        // Validate UUID format
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $eventId)) {
            return Response::error('Invalid event ID format. Expected UUID.');
        }

        // Get user's integration IDs for authorization
        $userIntegrationIds = $user->integrations()->pluck('id')->toArray();

        if (empty($userIntegrationIds)) {
            return Response::error('No integrations found for user.');
        }

        // Find the event
        $event = Event::query()
            ->whereIn('integration_id', $userIntegrationIds)
            ->where('id', $eventId)
            ->with(['integration', 'actor', 'target', 'blocks', 'tags'])
            ->first();

        if (! $event) {
            return Response::error('Event not found or access denied.');
        }

        $result = (new EventResource($event))->resolve(request());

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
            'id' => $schema->string()
                ->description('The UUID of the event to retrieve.')
                ->required(),
        ];
    }
}
