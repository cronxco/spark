<?php

namespace App\Livewire;

use App\Models\Event;
use App\Services\Receipt\ReceiptMatchingActions;
use App\Services\Receipt\ReceiptMatchState;
use App\Traits\AuthorizesOwnership;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptDetail extends Component
{
    use AuthorizesOwnership;

    public Event $receipt;

    public bool $showMatchModal = false;

    public string $transactionSearch = '';

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

        try {
            app(ReceiptMatchingActions::class)->link($this->receipt, $transaction);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('notify', ['type' => 'error', 'message' => $exception->getMessage()]);

            return;
        }

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
        app(ReceiptMatchingActions::class)->unlink($this->receipt);

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Match removed successfully',
        ]);

        $this->mount($this->receipt->id); // Refresh data
    }

    public function retryMatch(): void
    {
        $this->authorizeReceipt();
        try {
            app(ReceiptMatchingActions::class)->retry($this->receipt);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('notify', ['type' => 'error', 'message' => $exception->getMessage()]);

            return;
        }
        $this->receipt->refresh();
        $this->dispatch('notify', ['type' => 'success', 'message' => 'Searching for a transaction']);
    }

    public function markNoMatch(): void
    {
        $this->authorizeReceipt();
        app(ReceiptMatchingActions::class)->markNoMatch($this->receipt);
        $this->receipt->refresh();
    }

    public function downloadOriginalEmail(): ?StreamedResponse
    {
        $this->authorizeReceipt();

        $s3Key = $this->receipt->event_metadata['raw_email_s3_key'] ?? null;

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
                    'message' => 'Original email no longer kept',
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
        $relationship = ReceiptMatchState::link($this->receipt);

        if (! $relationship) {
            return null;
        }

        return Event::forUser(Auth::id())->with('target')->find($relationship->to_id);
    }

    public function getCandidateMatchesProperty(): array
    {
        return ReceiptMatchState::candidates($this->receipt);
    }

    public function render(): View
    {
        return view('livewire.receipt-detail', [
            'matchedTransaction' => $this->matchedTransaction,
            'matchedRelationship' => ReceiptMatchState::link($this->receipt),
            'candidateMatches' => $this->candidateMatches,
            'matchingStatus' => ReceiptMatchState::status($this->receipt),
            'matchingState' => ReceiptMatchState::state($this->receipt),
            'searchTransactions' => $this->showMatchModal
                ? app(ReceiptMatchingActions::class)->search($this->receipt, $this->transactionSearch)
                : collect(),
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
}
