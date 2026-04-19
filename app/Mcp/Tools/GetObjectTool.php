<?php

namespace App\Mcp\Tools;

use App\Http\Resources\EventObjectResource;
use App\Http\Resources\EventResource;
use App\Services\Mobile\EventLookup;
use App\Services\Mobile\ObjectLookup;
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

        if (! preg_match(EventLookup::UUID_REGEX, $objectId)) {
            return Response::error('Invalid object ID format. Expected UUID.');
        }

        $lookup = app(ObjectLookup::class);
        $object = $lookup->find($user, $objectId);

        if (! $object) {
            return Response::error('Object not found or access denied.');
        }

        $result = (new EventObjectResource($object))->resolve(request());

        $includeEvents = $request->get('include_events', true);
        if ($includeEvents) {
            $eventLimit = (int) $request->get('event_limit', ObjectLookup::EVENT_LIMIT_DEFAULT);
            $recentEvents = $lookup->recentEvents($object, $user, $eventLimit);
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
                ->min(1)
                ->max(25)
                ->default(10),
        ];
    }
}
