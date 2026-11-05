<?php

namespace App\Livewire;

use Livewire\Component;

class HotkeyHelpModal extends Component
{
    public bool $showHelpModal = false;

    protected $listeners = [
        'toggle-hotkey-help' => 'toggleModal',
    ];

    public function toggleModal(): void
    {
        $this->showHelpModal = ! $this->showHelpModal;
    }

    public function render()
    {
        return view('livewire.hotkey-help-modal');
    }
}
