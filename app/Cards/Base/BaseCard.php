<?php

namespace App\Cards\Base;

use App\Cards\Contracts\StreamCard;
use App\Models\User;

abstract class BaseCard implements StreamCard
{
    /**
     * Default implementation: cards don't trigger syncs.
     */
    public function shouldTriggerSync(): bool
    {
        return false;
    }

    /**
     * Default implementation: no sync jobs.
     */
    public function getSyncJobs(User $user, string $date): array
    {
        return [];
    }

    /**
     * Default implementation: most cards don't require interaction.
     */
    public function requiresInteraction(): bool
    {
        return false;
    }

    /**
     * Default implementation: do nothing on interaction.
     */
    public function markInteracted(User $user, string $date): void
    {
        // Override in subclasses if needed
    }

    /**
     * Default implementation: medium priority.
     */
    public function getPriority(): int
    {
        return 50;
    }

    /**
     * Default implementation: use class name as ID.
     */
    public function getId(): string
    {
        return class_basename(static::class);
    }

    /**
     * Default implementation: generic icon.
     */
    public function getIcon(): string
    {
        return 'fas-layer-group';
    }

    /**
     * Default implementation: empty data.
     */
    public function getData(User $user, string $date): array
    {
        return [];
    }
}
