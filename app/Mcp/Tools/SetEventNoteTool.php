<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Compact\CompactEventResource;
use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\EventNoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('set-event-note')]
class SetEventNoteTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'Set or clear the user-authored note attached to an event. Pass null or an empty string to clear it.';

    public function __construct(protected EventNoteService $notes) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'data:write')) {
            return $error;
        }

        $id = $request->get('event_id');
        $note = $request->get('note');
        if (! is_string($id) || ($note !== null && ! is_string($note)) || (is_string($note) && mb_strlen($note) > 10000)) {
            return Response::error('event_id is required; note must be a string up to 10,000 characters or null.');
        }

        $event = $this->notes->set($request->user(), $id, $note);
        if (! $event) {
            return Response::error('Event not found or access denied.');
        }

        return Response::json((new CompactEventResource($event))->resolve(request()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->description('UUID of the event.')->required(),
            'note' => $schema->string()->description('Note content. Omit or provide an empty value to clear it.'),
        ];
    }
}
