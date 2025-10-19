<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ArchiveFinancialAccount extends Component
{
    public EventObject $account;

    public ?string $archiveDate = null;

    public bool $showModal = false;

    protected $rules = [
        'archiveDate' => 'required|date|before_or_equal:today',
    ];

    protected $messages = [
        'archiveDate.required' => 'Please select an archive date.',
        'archiveDate.date' => 'Please enter a valid date.',
        'archiveDate.before_or_equal' => 'Archive date cannot be in the future.',
    ];

    public function mount(EventObject $account): void
    {
        // Ensure user owns this account
        if ($account->user_id !== Auth::id()) {
            abort(403);
        }

        $this->account = $account;
        $this->archiveDate = now()->toDateString();
    }

    public function archive(): void
    {
        $this->validate();

        $archiveDate = Carbon::parse($this->archiveDate);

        // Format date in UK style: "1st January 2025"
        $formattedDate = $archiveDate->format('jS F Y');

        // Get the integration for creating the balance event
        // First check the EventObject's integration relationship
        $integration = $this->account->integration;

        // If no direct relationship, check metadata for integration_id
        if (! $integration && isset($this->account->metadata['integration_id'])) {
            $integration = Integration::find($this->account->metadata['integration_id']);
        }

        if ($integration) {
            $plugin = new FinancialPlugin;

            // Create final balance event with zero balance
            $plugin->createBalanceEvent($integration, $this->account, [
                'date' => $this->archiveDate,
                'balance' => 0,
                'notes' => "Archived on {$formattedDate}",
            ]);
        } else {
            // If still no integration found, we need to create or find one
            // This handles edge cases where accounts might not have an integration
            $integration = Integration::firstOrCreate(
                [
                    'user_id' => Auth::id(),
                    'service' => 'manual_account',
                    'name' => 'Manual Accounts',
                ],
                [
                    'configuration' => [],
                    'state' => [],
                ]
            );

            $plugin = new FinancialPlugin;
            $plugin->createBalanceEvent($integration, $this->account, [
                'date' => $this->archiveDate,
                'balance' => 0,
                'notes' => "Archived on {$formattedDate}",
            ]);
        }

        // Set deleted flag in metadata
        $currentMetadata = $this->account->metadata ?? [];
        $currentMetadata['deleted'] = true;
        $currentMetadata['archived_at'] = $archiveDate->toIso8601String();

        $this->account->update([
            'metadata' => $currentMetadata,
        ]);

        $this->showModal = false;
        $this->dispatch('account-archived');
        $this->dispatch('close-modal');
    }

    public function render(): View
    {
        return view('livewire.archive-financial-account');
    }
}
