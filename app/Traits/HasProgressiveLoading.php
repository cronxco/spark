<?php

namespace App\Traits;

use Livewire\Attributes\On;

/**
 * Trait for implementing priority-based progressive loading in Livewire components.
 *
 * This trait provides a chained loading mechanism where data is loaded in priority order.
 * Each tier completes before the next tier begins, ensuring the most important data
 * loads first while maintaining a responsive UI with skeleton loaders.
 *
 * Usage:
 * 1. Use this trait in your Livewire component
 * 2. Define getLoadingTiers() to return your tier configuration
 * 3. Call startProgressiveLoading() after mount to begin the chain
 */
trait HasProgressiveLoading
{
    /**
     * Track which tier is currently loading.
     */
    public int $currentLoadingTier = 0;

    /**
     * Track if progressive loading has started.
     */
    public bool $progressiveLoadingStarted = false;

    /**
     * Define the loading tiers for this component.
     * Each tier is an array of method names to call.
     * Methods within a tier are called in parallel (same request).
     *
     * Example:
     * return [
     *     1 => ['loadTags'],
     *     2 => ['loadCore'],
     *     3 => ['loadBlocks', 'loadMedia'],
     *     4 => ['loadRelationships'],
     *     5 => ['loadRelatedEvents'],
     * ];
     */
    abstract protected function getLoadingTiers(): array;

    /**
     * Start the progressive loading chain.
     * Call this at the end of mount() or via wire:init on a wrapper element.
     */
    public function startProgressiveLoading(): void
    {
        if ($this->progressiveLoadingStarted) {
            return;
        }

        $this->progressiveLoadingStarted = true;
        $this->loadNextTier();
    }

    /**
     * Load the next tier in the sequence.
     */
    #[On('progressive-load-next-tier')]
    public function loadNextTier(): void
    {
        $tiers = $this->getLoadingTiers();
        $this->currentLoadingTier++;

        if (! isset($tiers[$this->currentLoadingTier])) {
            // All tiers loaded
            return;
        }

        $methods = $tiers[$this->currentLoadingTier];

        // Call all methods in this tier (parallel within the same request)
        foreach ($methods as $method) {
            if (method_exists($this, $method)) {
                $this->$method();
            }
        }

        // Dispatch event to load next tier on next tick
        // Using js() to ensure the current render completes first
        $this->dispatch('progressive-load-next-tier');
    }

    /**
     * Check if a specific tier has been loaded.
     */
    public function tierLoaded(int $tier): bool
    {
        return $this->currentLoadingTier >= $tier;
    }

    /**
     * Check if all tiers have been loaded.
     */
    public function allTiersLoaded(): bool
    {
        $tiers = $this->getLoadingTiers();

        return $this->currentLoadingTier >= max(array_keys($tiers));
    }

    /**
     * Reset progressive loading state (useful for refreshing).
     */
    public function resetProgressiveLoading(): void
    {
        $this->currentLoadingTier = 0;
        $this->progressiveLoadingStarted = false;
    }
}
