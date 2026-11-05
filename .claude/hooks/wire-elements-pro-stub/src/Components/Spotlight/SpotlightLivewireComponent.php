<?php

/**
 * Sandbox stub for wire-elements/pro — see SpotlightServiceProvider.php in
 * this directory for context. Stands in for the real package's Livewire
 * component registered under the 'spotlight-pro' alias, which
 * resources/views/components/layouts/app.blade.php renders on every page
 * via `@livewire('spotlight-pro')`. Renders nothing — the Spotlight UI
 * just isn't interactive in this sandbox, which no test here exercises.
 */

namespace WireElements\Pro\Components\Spotlight;

use Livewire\Component;

class SpotlightLivewireComponent extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}
