<?php

namespace App\Livewire;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Models\Event;
use App\Models\Relationship;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class ReceiptDetail extends Component
{
    public Event $receipt;

    public bool $showMatchModal = false;

    public function mount(string $id): void
    {
        $this->receipt = Event::with(['target', 'blocks', 'integration'])
            ->where('service', 'receipt')
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
        $transaction = Event::find($transactionId);

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

    public function downloadOriginalEmail(): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
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
                'message' => 'Failed to download email: '.$e->getMessage(),
            ]);

            return null;
        }
    }

    public function deleteReceipt(): void
    {
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
        ])->title('Receipt Details - '.$this->receipt->target?->title ?? 'Receipt');
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
