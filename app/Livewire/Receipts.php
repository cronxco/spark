<?php

namespace App\Livewire;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Models\Event;
use App\Models\Relationship;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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
        $receipt = Event::find($receiptId);
        $transaction = Event::find($transactionId);

        if (! $receipt || ! $transaction) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Receipt or transaction not found',
            ]);

            return;
        }

        $matcher = new ReceiptTransactionMatcher();
        $confidence = $this->calculateMatchConfidence($receipt, $transaction);

        $matcher->createReceiptRelationship(
            $receipt,
            $transaction,
            $confidence,
            'manual'
        );

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Receipt matched successfully',
        ]);

        $this->closeMatchModal();
    }

    private function calculateMatchConfidence(Event $receipt, Event $transaction): float
    {
        // Simple confidence calculation for manual matches
        $score = 0.5; // Base score for manual match

        // Amount match
        if ($receipt->value === $transaction->value) {
            $score += 0.3;
        }

        // Time proximity (within same day)
        if ($receipt->time->isSameDay($transaction->time)) {
            $score += 0.2;
        }

        return min(1.0, $score);
    }

    public function removeMatch(string $receiptId): void
    {
        $receipt = Event::find($receiptId);

        if (! $receipt) {
            return;
        }

        // Find and delete the receipt_for relationship
        Relationship::where('from_type', Event::class)
            ->where('from_id', $receiptId)
            ->where('type', 'receipt_for')
            ->delete();

        // Update merchant metadata
        $merchant = $receipt->target;
        if ($merchant) {
            $metadata = $merchant->metadata ?? [];
            $metadata['is_matched'] = false;
            $metadata['needs_review'] = false;
            unset($metadata['matched_transaction_id'], $metadata['matched_at']);
            $merchant->update(['metadata' => $metadata]);
        }

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Match removed successfully',
        ]);
    }

    public function deleteReceipt(string $receiptId): void
    {
        $receipt = Event::find($receiptId);

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
        $query = Event::where('service', 'receipt')
            ->where('domain', 'money')
            ->where('action', 'receipt_received_from')
            ->with(['target', 'blocks', 'integration']);

        // Apply status filter
        if ($this->statusFilter === 'matched') {
            $query->whereHas('target', function ($q) {
                $q->whereJsonContains('metadata->is_matched', true);
            });
        } elseif ($this->statusFilter === 'unmatched') {
            $query->whereHas('target', function ($q) {
                $q->where(function ($subQuery) {
                    $subQuery->whereJsonContains('metadata->is_matched', false)
                        ->orWhereNull('metadata->is_matched');
                })->where(function ($subQuery) {
                    $subQuery->whereJsonContains('metadata->needs_review', false)
                        ->orWhereNull('metadata->needs_review');
                });
            });
        } elseif ($this->statusFilter === 'review') {
            $query->whereHas('target', function ($q) {
                $q->whereJsonContains('metadata->needs_review', true);
            });
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
        return view('livewire.receipts', [
            'receipts' => $this->receipts,
        ]);
    }
}
