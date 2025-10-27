<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Tags\Tag;

#[Title('Account Details')]
class FinancialAccountShow extends Component
{
    use WithPagination;

    public EventObject $account;
    public bool $showSidebar = false;
    public bool $metadataOpen = false;
    public bool $showEditModal = false;
    public bool $showArchiveModal = false;
    public bool $showAddBalanceModal = false;
    public bool $showCreateTagModal = false;
    public int $perPage = 25;
    public array $sortBy = ['column' => 'time', 'direction' => 'desc'];

    public function mount(EventObject $account): void
    {
        // Ensure user owns this account
        if ($account->user_id !== Auth::id()) {
            abort(403);
        }

        // Eager load tags
        $this->account->load('tags');
    }

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
    }

    public function openEditModal(): void
    {
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function openArchiveModal(): void
    {
        $this->showArchiveModal = true;
    }

    public function closeArchiveModal(): void
    {
        $this->showArchiveModal = false;
    }

    public function openAddBalanceModal(): void
    {
        $this->showAddBalanceModal = true;
    }

    public function closeAddBalanceModal(): void
    {
        $this->showAddBalanceModal = false;
    }

    public function deleteAccount(): void
    {
        // Ensure user owns this account
        if ($this->account->user_id !== Auth::id()) {
            abort(403);
        }

        // Delete all related balance events first
        Event::where('actor_id', $this->account->id)
            ->where('service', 'manual_account')
            ->delete();

        // Delete the account object
        $this->account->delete();

        // Redirect to money index page
        $this->redirect(route('money'));
    }

    public function headers(): array
    {
        return [
            ['key' => 'time', 'label' => 'Date', 'sortable' => true],
            ['key' => 'balance', 'label' => 'Balance', 'sortable' => false],
            ['key' => 'notes', 'label' => 'Notes', 'sortable' => false],
        ];
    }

    public function getIsNegativeBalanceProperty(): bool
    {
        return $this->account->metadata['is_negative_balance'] ?? false;
    }

    public function getCurrencySymbolProperty(): string
    {
        $currency = $this->account->metadata['currency'] ?? 'GBP';
        $currencySymbols = [
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
        ];

        return $currencySymbols[$currency] ?? $currency;
    }

    public function render(): View
    {
        $plugin = new FinancialPlugin;
        $metadata = $this->account->metadata;
        $accountType = $metadata['account_type'] ?? '';
        $provider = $metadata['provider'] ?? '';
        $accountNumber = $metadata['account_number'] ?? null;
        $sortCode = $metadata['sort_code'] ?? null;
        $currency = $metadata['currency'] ?? 'GBP';
        $interestRate = $metadata['interest_rate'] ?? null;
        $startDate = $metadata['start_date'] ?? null;

        // Get account type label
        $accountTypeLabels = [
            'current_account' => 'Current Account',
            'savings_account' => 'Savings Account',
            'mortgage' => 'Mortgage',
            'investment_account' => 'Investment Account',
            'credit_card' => 'Credit Card',
            'loan' => 'Loan',
            'pension' => 'Pension',
            'other' => 'Other',
        ];
        $accountTypeLabel = $accountTypeLabels[$accountType] ?? $accountType;

        // Get currency symbol
        $currencySymbols = [
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
        ];
        $currencySymbol = $currencySymbols[$currency] ?? $currency;

        // Get current balance from latest event
        $latestBalance = $plugin->getLatestBalance($this->account);

        // Handle different balance storage formats
        if ($latestBalance) {
            if (isset($latestBalance->event_metadata['balance'])) {
                // Manual accounts store balance in event_metadata
                $currentBalance = $latestBalance->event_metadata['balance'];
            } else {
                // Monzo/GoCardless store balance in value field (integer cents)
                $currentBalance = $latestBalance->formatted_value;
            }
        } elseif (in_array($this->account->type, ['monzo_pot', 'monzo_archived_pot']) && ! empty(($this->account->metadata['balance'] ?? null))) {
            // Monzo pots now create balance events, but fallback to metadata if no events exist
            $currentBalance = (float) ($this->account->metadata['balance'] ?? 0);
        } else {
            $currentBalance = null;
        }

        // For negative balance accounts, invert the sign for display
        if ($this->isNegativeBalance && $currentBalance !== null) {
            $displayBalance = -$currentBalance;
        } else {
            $displayBalance = $currentBalance;
        }

        // Get balance history with pagination
        $balanceEventsQuery = $plugin->getBalanceEventsQuery($this->account);

        // Apply sorting
        $sortColumn = $this->sortBy['column'] ?? 'time';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $balanceEventsQuery->orderBy($sortColumn, $sortDirection);

        $balanceEvents = $balanceEventsQuery->paginate($this->perPage);

        return view('livewire.money.show', [
            'metadata' => $metadata,
            'accountTypeLabel' => $accountTypeLabel,
            'provider' => $provider,
            'accountNumber' => $accountNumber,
            'sortCode' => $sortCode,
            'currency' => $currency,
            'currencySymbol' => $currencySymbol,
            'interestRate' => $interestRate,
            'startDate' => $startDate,
            'currentBalance' => $currentBalance,
            'displayBalance' => $displayBalance,
            'latestBalance' => $latestBalance,
            'balanceEvents' => $balanceEvents,
        ]);
    }

    public function handleAccountArchived(): void
    {
        $this->closeArchiveModal();
        $this->redirect(route('money'));
    }

    public function addTag(string $value, ?string $type = null): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        // If type not explicitly provided, infer from value prefix (e.g., type:label or type_label)
        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            // Strip matching prefix from the visible value if present
            if (preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        // Default free-form tags to 'spark' unless they are emoji-only
        if ($detectedType === null) {
            $detectedType = preg_match('/^\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?(?:\\x{200D}\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?)*$/u', $name) === 1
                ? 'emoji'
                : 'spark';
        }

        $tag = Tag::findOrCreate($name, $detectedType);
        // Ensure type persisted in case library returned an existing tag without the type set
        if (($tag->type ?? null) !== $detectedType) {
            $tag->type = $detectedType;
            $tag->save();
        }

        $this->account->attachTag($tag);
        $this->account->refresh()->loadMissing('tags');
    }

    public function removeTag(string $value, ?string $type = null): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        // If type not explicitly provided, infer from value prefix (e.g., type:label or type_label)
        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            // Strip matching prefix from the visible value if present
            if (preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        // Default free-form tags to 'spark' unless they are emoji-only
        if ($detectedType === null) {
            $detectedType = preg_match('/^\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?(?:\\x{200D}\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?)*$/u', $name) === 1
                ? 'emoji'
                : 'spark';
        }

        $this->account->detachTag($name, $detectedType);
        $this->account->refresh()->loadMissing('tags');
    }

    public function notifyCopied(string $what): void
    {
        $this->success($what . ' copied to clipboard!');
    }

    public function openCreateTagModal(): void
    {
        $this->showCreateTagModal = true;
    }

    public function closeCreateTagModal(): void
    {
        $this->showCreateTagModal = false;
    }

    public function handleTagCreated(): void
    {
        $this->account->refresh()->loadMissing('tags');
        $this->showCreateTagModal = false;
    }

    protected function getListeners(): array
    {
        return [
            'close-modal' => 'closeEditModal',
            'close-add-balance-modal' => 'closeAddBalanceModal',
            'account-updated' => '$refresh',
            'account-archived' => 'handleAccountArchived',
            'balance-updated' => '$refresh',
            // Spotlight command events
            'open-balance-modal' => 'openAddBalanceModal',
            'open-edit-modal' => 'openEditModal',
            'open-archive-modal' => 'openArchiveModal',
        ];
    }
}
