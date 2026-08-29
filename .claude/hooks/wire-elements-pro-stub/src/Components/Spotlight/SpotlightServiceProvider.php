<?php

/**
 * Sandbox stub for wire-elements/pro, a licensed package this environment
 * has no credentials for. See .claude/hooks/session-start.sh — only ever
 * wired into the autoloader for a session-local `composer install`, never
 * part of the real, committed dependency tree.
 */

namespace WireElements\Pro\Components\Spotlight;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class SpotlightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // The real package registers its Livewire component under this
        // alias; resources/views/components/layouts/app.blade.php renders
        // it on every page via `@livewire('spotlight-pro')`.
        Livewire::component('spotlight-pro', SpotlightLivewireComponent::class);
    }
}
