<?php

namespace App\Services\Flint\Ask;

use App\Models\User;
use App\Services\Actions\ActionReceipt;
use App\Services\Flint\FlintLinkedEventCards;
use Laravel\Ai\Storage\StoredMessage;

/**
 * Turns a stored conversation message into what the app renders: the text,
 * the records it links to, the changes it made with each one's present Undo
 * state, the steps Flint took, cards for the events it linked, and the
 * follow-up questions it suggested.
 *
 * Contract 2 added `steps`, `cards`, `follow_ups` and `elapsed_ms` to each
 * message, and `step` and `reasoning` frames to the stream. A contract 1
 * client ignores them.
 */
class AskFlintTranscript
{
    public const CONTRACT_VERSION = 2;

    /** Where a turn's timings are kept in the message's `meta`. */
    public const META_KEY = 'ask_flint';

    public function __construct(
        private AskFlintReferences $references,
        private FlintLinkedEventCards $cards,
    ) {}

    /** @return array<string, mixed> */
    public function message(User $user, StoredMessage $message): array
    {
        $isAssistant = $message->role === 'assistant';

        if (! $isAssistant) {
            return [
                'id' => $message->id,
                'role' => 'user',
                'text' => $message->content,
                'references' => [],
                'changes' => [],
                'steps' => [],
                'cards' => [],
                'follow_ups' => [],
                'elapsed_ms' => null,
                'status' => $message->status->value,
                'created_at' => $message->createdAt?->toIso8601String(),
            ];
        }

        $split = AskFlintFollowUps::split($message->content);
        $resolved = $this->references->resolve($user, $split['text']);
        $timings = is_array($message->meta[self::META_KEY] ?? null) ? $message->meta[self::META_KEY] : [];

        return [
            'id' => $message->id,
            'role' => 'assistant',
            'text' => $resolved['text'],
            'references' => $resolved['references'],
            'changes' => $this->changes($user, $message->steps),
            'steps' => AskFlintSteps::fromStored($message->steps, array_map('intval', (array) ($timings['steps'] ?? []))),
            'cards' => $this->cards($user, $resolved['text']),
            'follow_ups' => $split['follow_ups'],
            'elapsed_ms' => isset($timings['elapsed_ms']) ? (int) $timings['elapsed_ms'] : null,
            'status' => $message->status->value,
            'created_at' => $message->createdAt?->toIso8601String(),
        ];
    }

    /**
     * The message for a turn that has just finished, before it is read back
     * from the store.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    public function fresh(User $user, ?string $id, string $text, array $changes, array $steps = [], ?int $elapsedMs = null): array
    {
        $split = AskFlintFollowUps::split($text);
        $resolved = $this->references->resolve($user, $split['text']);

        return [
            'id' => $id,
            'role' => 'assistant',
            'text' => $resolved['text'],
            'references' => $resolved['references'],
            'changes' => $changes,
            'steps' => $steps,
            'cards' => $this->cards($user, $resolved['text']),
            'follow_ups' => $split['follow_ups'],
            'elapsed_ms' => $elapsedMs,
            'status' => 'completed',
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Cards for the events an answer links, as Up to Speed shows them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cards(User $user, string $text): array
    {
        return $this->cards->forText($text, $user->integrations()->pluck('id'), $user->id);
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
