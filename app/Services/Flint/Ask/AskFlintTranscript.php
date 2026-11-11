<?php

namespace App\Services\Flint\Ask;

use App\Models\User;
use App\Services\Actions\ActionReceipt;
use Laravel\Ai\Storage\StoredMessage;

/**
 * Turns a stored conversation message into what the app renders: the text,
 * the records it links to, and the changes it made with each one's present
 * Undo state.
 */
class AskFlintTranscript
{
    public const CONTRACT_VERSION = 1;

    public function __construct(private AskFlintReferences $references) {}

    /** @return array<string, mixed> */
    public function message(User $user, StoredMessage $message): array
    {
        $isAssistant = $message->role === 'assistant';
        $resolved = $isAssistant
            ? $this->references->resolve($user, $message->content)
            : ['text' => $message->content, 'references' => []];

        return [
            'id' => $message->id,
            'role' => $isAssistant ? 'assistant' : 'user',
            'text' => $resolved['text'],
            'references' => $resolved['references'],
            'changes' => $isAssistant ? $this->changes($user, $message->steps) : [],
            'status' => $message->status->value,
            'created_at' => $message->createdAt?->toIso8601String(),
        ];
    }

    /**
     * The message for a turn that has just finished, before it is read back
     * from the store.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    public function fresh(User $user, ?string $id, string $text, array $changes): array
    {
        $resolved = $this->references->resolve($user, $text);

        return [
            'id' => $id,
            'role' => 'assistant',
            'text' => $resolved['text'],
            'references' => $resolved['references'],
            'changes' => $changes,
            'status' => 'completed',
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Changes recorded in a stored turn's write-tool results.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function changes(User $user, array $steps): array
    {
        $writeTools = AskFlintTools::writeToolNames();

        return collect($steps)
            ->flatMap(fn (array $step): array => (array) ($step['tool_calls'] ?? []))
            ->filter(fn (mixed $call): bool => is_array($call) && in_array($call['name'] ?? null, $writeTools, true))
            ->map(function (array $call): ?array {
                $result = $call['result'] ?? null;
                $decoded = is_string($result) ? json_decode($result, true) : $result;

                return is_array($decoded) && is_array($decoded['change'] ?? null) ? $decoded['change'] : null;
            })
            ->filter()
            ->map(fn (array $change): array => AskFlintChangeLog::payload(
                is_string($change['receipt_id'] ?? null) ? ActionReceipt::find($user, $change['receipt_id']) : null,
                $change,
            ))
            ->values()
            ->all();
    }
}
