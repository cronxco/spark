<?php

namespace App\Ai\Tools\AskFlint;

use App\Services\Actions\ActionStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * Adds a tag to an event, through the registry's own tag action.
 */
class TagEventTool extends AskFlintWriteTool
{
    public function name(): string
    {
        return 'tag-event';
    }

    public function description(): string
    {
        return 'Add one tag to one event. Applies immediately; the app offers Undo.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->description('UUID of the event, from a read tool.')->required(),
            'name' => $schema->string()->description('The tag, e.g. "work lunch".')->required(),
        ];
    }

    protected function apply(Request $request): array
    {
        $receipt = $this->executor->run($this->user, $this->action('events.tag_add'), [
            'event_id' => $request['event_id'],
            'name' => $request['name'],
        ]);

        if ($receipt->status() !== ActionStatus::Succeeded) {
            return $this->receiptFailed($receipt->status(), $receipt->progress->error_message);
        }

        $event = $this->mutations->find($this->user, 'event', (string) $request['event_id']);

        if ($event === null) {
            return $this->notFound('Event');
        }

        return $this->done($this->changes->add($receipt, 'tag', 'event', $event, (string) $receipt->progress->message));
    }
}
