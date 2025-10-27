<?php

namespace App\Spotlight\Actions;

use WireElements\Pro\Components\Spotlight\Spotlight;
use WireElements\Pro\Components\Spotlight\SpotlightAction;

class ClearBinAction extends SpotlightAction
{
    /**
     * Description shown in the action button.
     */
    public function description(): string
    {
        return 'Clear Bin';
    }

    /**
     * Execute the action.
     */
    public function execute(Spotlight $spotlight): void
    {
        // Dispatch event to show confirmation modal
        // The Livewire component on the admin.bin.index page will handle this
        $spotlight->dispatch('confirm-clear-bin');

        // Optionally dispatch a toast notification
        $spotlight->dispatch('toast', [
            'type' => 'warning',
            'message' => 'Please confirm bin deletion',
        ]);
    }
}
