<?php

namespace App\Livewire;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Models\Event;
use App\Models\Relationship;
use App\Traits\AuthorizesOwnership;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptDetail extends Component
{
    use AuthorizesOwnership;

    public Event $receipt;

    public bool $showMatchModal = false;

    public function mount(string $id): void
    {
        // Scope to the authenticated user via the receipt's integration —
        // a receipt belonging to another user yields a 404.
        $this->receipt = Event::with(['target', 'blocks', 'integration'])
            ->where('service', 'receipt')
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->findOrFail($id);
    }

    public function openMatchModal(): void
    {
        $this->showMatchModal = true;
    }

    public function closeMatchModal(): void
    {
        $this->showMatchModal = false;
    }

    public function createManualMatch(string $transactionId): void
    {
        $this->authorizeReceipt();

        $transaction = Event::whereKey($transactionId)
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->first();

        if (! $transaction) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Transaction not found',
            ]);

            return;
        }

        $matcher = new ReceiptTransactionMatcher;
        $confidence = $this->calculateMatchConfidence($this->receipt, $transaction);

        $matcher->createReceiptRelationship(
            $this->receipt,
            $transaction,
            $confidence,
            'manual'
        );

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Receipt matched successfully',
        ]);

        $this->closeMatchModal();
        $this->mount($this->receipt->id); // Refresh data
    }

    public function removeMatch(): void
    {
        $this->authorizeReceipt();

        // Find and delete the receipt_for relationship
        Relationship::where('from_type', Event::class)
            ->where('from_id', $this->receipt->id)
            ->where('type', 'receipt_for')
            ->delete();

        // Update merchant metadata
        $merchant = $this->receipt->target;
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

        $this->mount($this->receipt->id); // Refresh data
    }

    public function downloadOriginalEmail(): ?StreamedResponse
    {
        $this->authorizeReceipt();

        $merchant = $this->receipt->target;
        $s3Key = $merchant?->metadata['s3_object_key'] ?? null;

        if (! $s3Key) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Original email not available',
            ]);

            return null;
        }

        try {
            $disk = Storage::disk('s3-receipts');
            if (! $disk->exists($s3Key)) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Email file not found in storage',
                ]);

                return null;
            }

            return response()->streamDownload(function () use ($disk, $s3Key) {
                echo $disk->get($s3Key);
            }, basename($s3Key), [
                'Content-Type' => 'message/rfc822',
            ]);
        } catch (Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to download email: ' . $e->getMessage(),
            ]);

            return null;
        }
    }

    public function deleteReceipt(): void
    {
        $this->authorizeReceipt();

        // Soft delete the receipt event (cascade will handle blocks and relationships)
        $this->receipt->delete();

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Receipt deleted successfully',
        ]);

        $this->redirect(route('receipts.index'));
    }

    public function getMatchedTransactionProperty(): ?Event
    {
        $relationship = Relationship::where('from_type', Event::class)
            ->where('from_id', $this->receipt->id)
            ->where('type', 'receipt_for')
            ->first();

        if (! $relationship) {
            return null;
        }

        return Event::find($relationship->to_id);
    }

    public function getCandidateMatchesProperty(): array
    {
        $merchant = $this->receipt->target;
        $metadata = $merchant?->metadata ?? [];

        return $metadata['match_candidates'] ?? [];
    }

    public function render(): View
    {
        return view('livewire.receipt-detail', [
            'matchedTransaction' => $this->matchedTransaction,
            'candidateMatches' => $this->candidateMatches,
        ])->title('Receipt Details - ' . $this->receipt->target?->title ?? 'Receipt');
    }

    /**
     * Re-assert ownership of the hydrated receipt. Livewire rehydrates public
     * Eloquent properties by key without re-running mount(), so every action
     * that touches $this->receipt must guard against a tampered snapshot.
     */
    private function authorizeReceipt(): void
    {
        $this->authorizeOwner($this->receipt->integration?->user_id);
    }

    private function calculateMatchConfidence(Event $receipt, Event $transaction): float
    {
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
}
