<?php

namespace App\Cards\Contracts;

use App\Models\User;
use Carbon\Carbon;

interface StreamCard
{
    /**
     * Determine if this card should be shown to the user.
     */
    public function isEligible(Carbon $now, User $user, string $date): bool;

    /**
     * Get the priority for ordering cards (higher = shown earlier).
     */
    public function getPriority(): int;

    /**
     * Get a unique identifier for this card type.
     */
    public function getId(): string;

    /**
     * Get the display title for this card.
     */
    public function getTitle(): string;

    /**
     * Get the icon name for this card (Heroicons).
     */
    public function getIcon(): string;

    /**
     * Get the Blade view path for rendering this card.
     */
    public function getViewPath(): string;

    /**
     * Get the data to pass to the Blade view.
     */
    public function getData(User $user, string $date): array;

    /**
     * Whether this card requires user interaction to be marked complete.
     * If false, card is auto-marked complete when viewed.
     */
    public function requiresInteraction(): bool;

    /**
     * Whether this card should trigger background sync jobs.
     */
    public function shouldTriggerSync(): bool;

    /**
     * Get sync jobs to dispatch when this card loads.
     *
     * @return array<class-string, array> Array of [JobClass::class => [constructor params]]
     */
    public function getSyncJobs(User $user, string $date): array;

    /**
     * Handle card interaction (e.g., check-in saved).
     * Called when user completes an interactive action.
     */
    public function markInteracted(User $user, string $date): void;
}
