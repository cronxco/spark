<?php

namespace App\Mcp\Tools;

use App\Http\Resources\EventObjectResource;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetObjectTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Retrieve full details for a specific object (entity) by its UUID.
        Returns the object with its metadata, and optionally recent events where it appears as actor or target.
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

        $objectId = $request->get('id');

        if (empty($objectId)) {
            return Response::error('Object ID is required.');
        }

        // Validate UUID format
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $objectId)) {
            return Response::error('Invalid object ID format. Expected UUID.');
        }

        // Find the object (EventObjects are user-scoped)
        $object = EventObject::query()
            ->where('user_id', $user->id)
            ->where('id', $objectId)
            ->first();

        if (! $object) {
            return Response::error('Object not found or access denied.');
        }

        $result = (new EventObjectResource($object))->resolve(request());

        // Include recent events if requested
        $includeEvents = $request->get('include_events', true);
        $eventLimit = (int) $request->get('event_limit', 10);
        $eventLimit = max(1, min($eventLimit, 25));

        if ($includeEvents) {
            // Get user's integration IDs
            $userIntegrationIds = $user->integrations()->pluck('id')->toArray();

            // Get recent events where this object is actor or target
            $recentEvents = Event::query()
                ->whereIn('integration_id', $userIntegrationIds)
                ->where(function ($q) use ($objectId) {
                    $q->where('actor_id', $objectId)
                        ->orWhere('target_id', $objectId);
                })
                ->with(['integration', 'actor', 'target', 'blocks', 'tags'])
                ->orderBy('time', 'desc')
                ->limit($eventLimit)
                ->get();

            $result['recent_events'] = EventResource::collection($recentEvents)->resolve(request());
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
            'id' => $schema->string()
                ->description('The UUID of the object to retrieve.')
                ->required(),

            'include_events' => $schema->boolean()
                ->description('Include recent events where this object appears as actor or target. Default: true.')
                ->default(true),

            'event_limit' => $schema->integer()
                ->description('Maximum number of recent events to include (default: 10, max: 25).')
                ->minimum(1)
                ->maximum(25)
                ->default(10),
        ];
    }
}
