<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\EventObject;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Financial Account')]
class EditFinancialAccount extends Component
{
    public EventObject $account;
    public array $metadata = [];
    public array $editableFields = [];

    public function mount(EventObject $account): void
    {
        // Ensure user owns this account
        if ($account->user_id !== Auth::id()) {
            abort(403);
        }

        $this->account = $account;
        $this->metadata = $account->metadata;

        $plugin = new FinancialPlugin;
        $this->editableFields = $plugin->getEditableMetadataFields($account);
    }

    public function updateMetadata(): void
    {
        $plugin = new FinancialPlugin;
        $success = $plugin->updateAccountMetadata($this->account, $this->metadata);

        if ($success) {
            $this->dispatch('account-updated');
            $this->dispatch('closeModal');
        } else {
            $this->dispatch('account-update-failed');
        }
    }

    public function render(): View
    {
        return view('livewire.edit-financial-account');
    }
}
