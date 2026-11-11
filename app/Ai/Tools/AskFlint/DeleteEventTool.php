<?php

namespace App\Ai\Tools\AskFlint;

use App\Services\Actions\ActionStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * Moves one event to the bin, through the registry's own delete action. It
 * is a soft delete: the receipt's Undo restores it, and so does the bin.
 */
class DeleteEventTool extends AskFlintWriteTool
{
    public function name(): string
    {
        return 'delete-event';
    }

    public function description(): string
    {
        return 'Move one event to the bin. Use only when the user asks to delete or remove a specific event and you have identified exactly which one. One event per call. Applies immediately; the app offers Undo and the event stays recoverable from the bin.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->description('UUID of the event, from a read tool.')->required(),
        ];
    }

    protected function apply(Request $request): array
    {
        $id = (string) $request['event_id'];
        $event = Str::isUuid($id) ? $this->mutations->find($this->user, 'event', $id) : null;

        if ($event === null) {
            return $this->notFound('Event');
        }

        $receipt = $this->executor->run($this->user, $this->action('events.delete'), ['event_id' => $id], confirmed: true);

        if ($receipt->status() !== ActionStatus::Succeeded) {
            return $this->receiptFailed($receipt->status(), $receipt->progress->error_message);
        }

        return $this->done($this->changes->add($receipt, 'delete', 'event', $event, 'Moved to the bin'));
    }
}
