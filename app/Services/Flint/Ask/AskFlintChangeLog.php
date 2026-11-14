<?php

namespace App\Services\Flint\Ask;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\Actions\ActionReceipt;
use Illuminate\Support\Str;

/**
 * The changes one Ask Flint turn made, each tied to the receipt that undoes
 * it. The write tools add to it; the response and the stream read from it.
 */
class AskFlintChangeLog
{
    /** A model that keeps writing is stopped here, whatever it was asked. */
    public const MAX_PER_TURN = 5;

    /** @var array<int, array<string, mixed>> */
    private array $changes = [];

    /**
     * A change with its receipt's present Undo state, so a transcript read
     * later does not offer an Undo that has expired or already run.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public static function payload(?ActionReceipt $receipt, array $change): array
    {
        return [
            'receipt_id' => $receipt?->id() ?? ($change['receipt_id'] ?? null),
            'operation' => (string) ($change['operation'] ?? 'update'),
            'kind' => (string) ($change['kind'] ?? 'event'),
            'id' => (string) ($change['id'] ?? ''),
            'title' => (string) ($change['title'] ?? ''),
            'summary' => (string) ($change['summary'] ?? ''),
            'undo' => [
                'available' => $receipt?->canUndo() ?? false,
                'expires_at' => $receipt?->undoPayload()['expires_at'] ?? null,
                'undone' => $receipt?->isUndone() ?? false,
            ],
        ];
    }

    /** A short name for a record, for a change card or a reference chip. */
    public static function label(Event|EventObject|Block $entity): string
    {
        $label = match (true) {
            $entity instanceof Event => trim(Str::of((string) $entity->action)->replace('_', ' ')->ucfirst() . ' ' . ($entity->target?->title ?? '')),
            $entity instanceof Block => (string) ($entity->title ?: Str::of((string) $entity->block_type)->replace('_', ' ')->ucfirst()),
            default => (string) $entity->title,
        };

        return Str::limit($label !== '' ? $label : 'Untitled', 80);
    }

    public function limitReached(): bool
    {
        return count($this->changes) >= self::MAX_PER_TURN;
    }

    /**
     * @return array<string, mixed> the change as the client receives it
     */
    public function add(ActionReceipt $receipt, string $operation, string $kind, Event|EventObject|Block $entity, string $summary): array
    {
        return $this->changes[] = self::payload($receipt, [
            'operation' => $operation,
            'kind' => $kind,
            'id' => (string) $entity->getKey(),
            'title' => self::label($entity),
            'summary' => $summary,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->changes;
    }
}
