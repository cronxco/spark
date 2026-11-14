<?php

namespace App\Ai\Tools\AskFlint;

use App\Http\Resources\Compact\CompactEventResource;
use App\Services\Actions\ActionOutcome;
use App\Services\EventNoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * Sets or clears the user's own note on an event.
 */
class SetEventNoteTool extends AskFlintWriteTool
{
    private const MAX_LENGTH = 10000;

    public function name(): string
    {
        return 'set-event-note';
    }

    public function description(): string
    {
        return 'Set the user-authored note on one event, replacing any note it has. Send an empty note to clear it. Applies immediately; the app offers Undo.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->description('UUID of the event, from a read tool.')->required(),
            'note' => $schema->string()->description('The note text. Empty clears the note.')->required(),
        ];
    }

    protected function apply(Request $request): array
    {
        $id = (string) $request['event_id'];
        $note = trim((string) ($request['note'] ?? ''));

        if (! Str::isUuid($id) || mb_strlen($note) > self::MAX_LENGTH) {
            return ['status' => 'failed', 'error' => 'event_id must be a UUID and the note at most 10,000 characters.'];
        }

        $before = $this->mutations->find($this->user, 'event', $id);

        if ($before === null) {
            return $this->notFound('Event');
        }

        $previous = $before->blocks
            ->first(fn ($block): bool => $block->block_type === CompactEventResource::NOTE_BLOCK_TYPE && $block->deleted_at === null)
            ?->getContent();

        $event = app(EventNoteService::class)->set($this->user, $id, $note === '' ? null : $note);

        if ($event === null) {
            return $this->notFound('Event');
        }

        $receipt = $this->executor->record(
            $this->user,
            $this->action('flint.ask_note'),
            ['event_id' => $id],
            ActionOutcome::succeeded($note === '' ? 'Note cleared' : 'Note saved', ['event_id' => $id], [
                'event_id' => $id,
                'previous_note' => $previous,
            ]),
        );

        $summary = $note === '' ? 'Note cleared' : 'Note: ' . Str::limit($note, 80);

        return $this->done($this->changes->add($receipt, 'note', 'event', $event, $summary));
    }
}
