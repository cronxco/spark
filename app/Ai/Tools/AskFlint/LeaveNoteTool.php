<?php

namespace App\Ai\Tools\AskFlint;

use App\Models\User;
use App\Services\Flint\FlintNoteService;
use App\Support\SparkAbility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Leaves a note for Flint's routines: the digest and coaching runs read
 * these the next time they run. It is what an `@flint` line in the app's
 * command bar used to write.
 *
 * A note is the user's own words kept for later, not a change to their data,
 * so it leaves no Undo card and does not count towards a reply's change
 * limit. The user can delete it from their notes in the Flint tab.
 */
class LeaveNoteTool implements Tool
{
    public const CAPABILITY = 'flint:write';

    public function __construct(private User $user, private FlintNoteService $notes) {}

    public function name(): string
    {
        return 'leave-note';
    }

    public function description(): string
    {
        return 'Keep a note for Flint\'s later digests and coaching, when the user asks Flint to remember, note or bear something in mind ("remember I\'m away next week", "note that I\'m cutting back on caffeine"). Write it in the user\'s own words. Not for logging something that happened: use log-event for that.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->description('The note, in the user\'s words.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        if (! SparkAbility::allows($this->user, self::CAPABILITY)) {
            return $this->failed('This session cannot write notes for Flint.');
        }

        $body = trim((string) ($request['body'] ?? ''));

        if ($body === '' || mb_strlen($body) > 10000) {
            return $this->failed('A note needs some text, up to 10,000 characters.');
        }

        try {
            $result = $this->notes->create($this->user, [
                'client_mutation_id' => (string) str()->uuid(),
                'authored_at' => now()->toIso8601String(),
                'body' => $body,
                'consent_version' => FlintNoteService::CONSENT_VERSION,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failed('The note could not be saved.');
        }

        if (! isset($result['note'])) {
            return $this->failed((string) ($result['message'] ?? 'The note could not be saved.'));
        }

        return json_encode([
            'status' => 'noted',
            'note' => 'Saved for Flint\'s next digest. The user can see and delete it under their notes in the Flint tab.',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function failed(string $message): string
    {
        return json_encode(['status' => 'failed', 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
