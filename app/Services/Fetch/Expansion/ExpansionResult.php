<?php

namespace App\Services\Fetch\Expansion;

use App\Models\Event;

/**
 * What one expansion run did.
 */
final readonly class ExpansionResult
{
    /**
     * @param  list<array{url: string, canonical_url: string, title: ?string, status: string, child_id: ?string}>  $entries
     */
    public function __construct(
        public ?Event $event,
        public array $entries,
        public bool $coldStart,
    ) {}

    public function countWithStatus(string $status): int
    {
        return count(array_filter($this->entries, fn (array $entry): bool => $entry['status'] === $status));
    }

    public function newCount(): int
    {
        return $this->countWithStatus(LinkListExpander::STATUS_QUEUED) + $this->countWithStatus(LinkListExpander::STATUS_DISABLED);
    }
}
