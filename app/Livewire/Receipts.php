<?php

namespace App\Livewire;

use App\Models\Event;
use App\Services\Receipt\ReceiptMatchingActions;
use App\Services\Receipt\ReceiptMatchState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Receipts')]
class Receipts extends Component
{
    use WithPagination;

    public ?string $search = null;

    public ?string $statusFilter = 'all'; // all, matched, unmatched, review

    public array $sortBy = ['column' => 'time', 'direction' => 'desc'];

    public int $perPage = 25;

    public ?string $selectedReceiptId = null;

    public bool $showMatchModal = false;

    public string $transactionSearch = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => 'all'],
        'sortBy' => ['except' => ['column' => 'time', 'direction' => 'desc']],
        'perPage' => ['except' => 25],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy['column'] === $column) {
            $this->sortBy['direction'] = $this->sortBy['direction'] === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = ['column' => $column, 'direction' => 'asc'];
        }

        $this->resetPage();
    }

    public function openMatchModal(string $receiptId): void
    {
        abort_unless($this->findOwnedEvent($receiptId)?->service === 'receipt', 404);
        $this->selectedReceiptId = $receiptId;
        $this->showMatchModal = true;
    }

    public function closeMatchModal(): void
    {
        $this->selectedReceiptId = null;
        $this->showMatchModal = false;
    }

    public function createManualMatch(string $receiptId, string $transactionId): void
    {
        $receipt = $this->findOwnedEvent($receiptId);
        $transaction = $this->findOwnedEvent($transactionId);

        if (! $receipt || ! $transaction) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Receipt or transaction not found',
            ]);

            return;
        }

        try {
            app(ReceiptMatchingActions::class)->link($receipt, $transaction);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('notify', ['type' => 'error', 'message' => $exception->getMessage()]);

            return;
        }

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Receipt matched successfully',
        ]);

        $this->closeMatchModal();
    }

    public function removeMatch(string $receiptId): void
    {
        $receipt = $this->findOwnedEvent($receiptId);

        if (! $receipt) {
            return;
        }

        app(ReceiptMatchingActions::class)->unlink($receipt);

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Match removed successfully',
        ]);
    }

    public function retryMatch(string $receiptId): void
    {
        $receipt = $this->findOwnedEvent($receiptId);
        abort_unless($receipt?->service === 'receipt', 404);
        try {
            app(ReceiptMatchingActions::class)->retry($receipt);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('notify', ['type' => 'error', 'message' => $exception->getMessage()]);

            return;
        }
        $this->dispatch('notify', ['type' => 'success', 'message' => 'Searching for a transaction']);
    }

    public function markNoMatch(string $receiptId): void
    {
        $receipt = $this->findOwnedEvent($receiptId);
        abort_unless($receipt?->service === 'receipt', 404);
        app(ReceiptMatchingActions::class)->markNoMatch($receipt);
    }

    public function deleteReceipt(string $receiptId): void
    {
        $receipt = $this->findOwnedEvent($receiptId);

        if (! $receipt || $receipt->service !== 'receipt') {
            return;
        }

        // Soft delete the receipt event (cascade will handle blocks and relationships)
        $receipt->delete();

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Receipt deleted successfully',
        ]);
    }

    public function getReceiptsProperty()
    {
        $query = $this->receiptQuery()->with(['target', 'blocks', 'integration']);

        // Apply status filter
        if ($this->statusFilter === 'matched') {
            $query->whereIn('id', ReceiptMatchState::links()->select('from_id'));
        } elseif ($this->statusFilter === 'unmatched') {
            $query->whereNotIn('id', ReceiptMatchState::links()->select('from_id'))
                ->where(function ($q) {
                    $q->whereNull('event_metadata->receipt_matching->status')
                        ->orWhere('event_metadata->receipt_matching->status', '!=', 'suggestions');
                });
        } elseif ($this->statusFilter === 'review') {
            $query->whereNotIn('id', ReceiptMatchState::links()->select('from_id'))
                ->where('event_metadata->receipt_matching->status', 'suggestions');
        }

        // Apply search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->whereHas('target', function ($subQuery) {
                    $subQuery->where('title', 'ilike', '%' . $this->search . '%');
                })->orWhereRaw('CAST(value AS TEXT) LIKE ?', ['%' . $this->search . '%']);
            });
        }

        // Apply sorting
        $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);

        return $query->paginate($this->perPage);
    }

    public function render(): View
    {
        $selected = $this->selectedReceiptId ? $this->findOwnedEvent($this->selectedReceiptId) : null;
        $candidates = $selected ? ReceiptMatchState::candidates($selected) : [];

        return view('livewire.receipts', [
            'receipts' => $this->receipts,
            'stats' => [
                'total' => $this->receiptQuery()->count(),
                'matched' => $this->receiptQuery()->whereIn('id', ReceiptMatchState::links()->select('from_id'))->count(),
                'review' => $this->receiptQuery()->whereNotIn('id', ReceiptMatchState::links()->select('from_id'))
                    ->where('event_metadata->receipt_matching->status', 'suggestions')->count(),
            ],
            'selectedReceipt' => $selected,
            'candidateTransactions' => $selected ? Event::forUser(Auth::id())->whereIn('id', collect($candidates)->pluck('transaction_id'))->with('target')->get() : collect(),
            'searchTransactions' => $selected && $this->showMatchModal
                ? app(ReceiptMatchingActions::class)->search($selected, $this->transactionSearch)
                : collect(),
        ]);
    }

    private function receiptQuery()
    {
        return Event::where('service', 'receipt')->where('domain', 'money')->where('action', 'had_receipt_from')
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()));
    }

    /**
     * Resolve an event by id, scoped to the authenticated user via its
     * integration. Returns null for events the user does not own.
     */
    private function findOwnedEvent(string $id): ?Event
    {
        return Event::whereKey($id)
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->first();
    }
}
