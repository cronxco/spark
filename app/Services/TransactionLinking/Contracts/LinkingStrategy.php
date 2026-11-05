<?php

namespace App\Services\TransactionLinking\Contracts;

use App\Models\Event;
use Illuminate\Support\Collection;

interface LinkingStrategy
{
    /**
     * Get the unique identifier for this strategy.
     */
    public function getIdentifier(): string;

    /**
     * Get a human-readable name for this strategy.
     */
    public function getName(): string;

    /**
     * Find potential linked transactions for the given event.
     *
     * @return Collection<int, array{
     *     target_event: Event,
     *     relationship_type: string,
     *     confidence: float,
     *     matching_criteria: array,
     *     value: int|null,
     *     value_multiplier: int|null,
     *     value_unit: string|null
     * }>
     */
    public function findLinks(Event $event): Collection;

    /**
     * Check if this strategy can process the given event.
     */
    public function canProcess(Event $event): bool;
}
