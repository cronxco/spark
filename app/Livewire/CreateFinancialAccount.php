<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class CreateFinancialAccount extends Component
{
    public string $name = '';
    public string $accountType = '';
    public string $provider = '';
    public ?string $accountNumber = null;
    public ?string $sortCode = null;
    public string $currency = 'GBP';
    public ?float $interestRate = null;
    public ?string $startDate = null;

    protected $rules = [
        'name' => 'required|string|max:255',
        'accountType' => 'required|string|in:current_account,savings_account,mortgage,investment_account,credit_card,loan,pension,other',
        'provider' => 'required|string|max:255',
        'accountNumber' => 'nullable|string|max:255',
        'sortCode' => 'nullable|string|max:8',
        'currency' => 'required|string|in:GBP,USD,EUR',
        'interestRate' => 'nullable|numeric|min:0|max:100',
        'startDate' => 'nullable|date',
    ];

    public function mount(): void
    {
        $this->currency = 'GBP';
    }

    public function save(): void
    {
        $this->validate();

        // Get or create the financial integration group
        $group = IntegrationGroup::where('user_id', Auth::id())
            ->where('service', 'financial')
            ->first();

        if (! $group) {
            $plugin = new FinancialPlugin;
            $group = $plugin->initializeGroup(Auth::user());
        }

        // Create the integration instance
        $integration = Integration::create([
            'user_id' => Auth::id(),
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
            'name' => $this->name,
            'instance_type' => 'accounts',
            'configuration' => [
                'account_type' => $this->accountType,
                'provider' => $this->provider,
                'account_number' => $this->accountNumber,
                'sort_code' => $this->sortCode,
                'currency' => $this->currency,
                'interest_rate' => $this->interestRate,
                'start_date' => $this->startDate,
            ],
        ]);

        // Create the financial account object using the plugin
        $plugin = new FinancialPlugin;
        $accountData = [
            'name' => $this->name,
            'account_type' => $this->accountType,
            'provider' => $this->provider,
            'account_number' => $this->accountNumber,
            'sort_code' => $this->sortCode,
            'currency' => $this->currency,
            'interest_rate' => $this->interestRate,
            'start_date' => $this->startDate,
        ];

        $plugin->upsertAccountObject($integration, $accountData);

        $this->dispatch('account-created');
        $this->reset();
        $this->redirectRoute('money');
    }

    public function render(): View
    {
        return view('livewire.create-financial-account');
    }
}
