<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\PresentsEventTimes;
use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Mobile\EventLookup;
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
    use PresentsEventTimes;
    use RequiresSparkAbility;

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Retrieve full details for a specific event by its UUID.
        Returns the complete event with actor, target, blocks, tags, and integration context.
        `time` is UTC; `local_time` is the same instant in the user's timezone at that moment
        (named in `timezone`, which follows time travel). Quote clock times from `local_time`.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'data:read')) {
            return $error;
        }
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $eventId = $request->get('id');

        if (empty($eventId)) {
            return Response::error('Event ID is required.');
        }

        if (! is_string($eventId)) {
            return Response::error('Event ID must be a string.');
        }

        if (! preg_match(EventLookup::UUID_REGEX, $eventId)) {
            return Response::error('Invalid event ID format. Expected UUID.');
        }

        $event = app(EventLookup::class)->find($user, $eventId);

        if (! $event) {
            return Response::error('Event not found or access denied.');
        }

        $result = $this->presentEvent($event, $user);

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
