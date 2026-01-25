<?php

namespace App\Jobs\Data\Receipt;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MatchReceiptToTransactionJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    public $backoff = [30, 120, 300];

    public function __construct(
        public Event $receiptEvent
    ) {}

    public function handle(): void
    {
        Log::info('Receipt: Starting transaction matching', [
            'receipt_id' => $this->receiptEvent->id,
            'merchant' => $this->receiptEvent->target->title ?? 'unknown',
            'amount' => $this->receiptEvent->value,
        ]);

        try {
            $matcher = new ReceiptTransactionMatcher;
            $candidates = $matcher->findCandidateMatches($this->receiptEvent);

            if ($candidates->isEmpty()) {
                Log::info('Receipt: No transaction candidates found', [
                    'receipt_id' => $this->receiptEvent->id,
                ]);

                // Update merchant metadata to indicate no match found yet
                $merchant = $this->receiptEvent->target;
                if ($merchant) {
                    $metadata = $merchant->metadata ?? [];
                    $metadata['is_matched'] = false;
                    $metadata['match_attempts'] = ($metadata['match_attempts'] ?? 0) + 1;
                    $metadata['last_match_attempt_at'] = now()->toIso8601String();
                    $merchant->update(['metadata' => $metadata]);
                }

                return;
            }

            $topMatch = $candidates->first();
            $autoMatchThreshold = config('services.receipt.auto_match_threshold', 0.8);
            $reviewThreshold = config('services.receipt.review_threshold', 0.5);

            if ($topMatch['confidence'] >= $autoMatchThreshold) {
                // Auto-match: Create relationship
                $matcher->createReceiptRelationship(
                    $this->receiptEvent,
                    $topMatch['transaction'],
                    $topMatch['confidence'],
                    'automatic'
                );

                Log::info('Receipt: Auto-matched to transaction', [
                    'receipt_id' => $this->receiptEvent->id,
                    'transaction_id' => $topMatch['transaction']->id,
                    'confidence' => $topMatch['confidence'],
                ]);
            } elseif ($topMatch['confidence'] >= $reviewThreshold) {
                // Medium confidence: Flag for review
                $matcher->flagForReview($this->receiptEvent, $candidates->take(3));

                Log::info('Receipt: Flagged for manual review', [
                    'receipt_id' => $this->receiptEvent->id,
                    'top_confidence' => $topMatch['confidence'],
                    'candidate_count' => $candidates->count(),
                ]);
            } else {
                // Low confidence: Do nothing, just log
                Log::info('Receipt: Low confidence matches only', [
                    'receipt_id' => $this->receiptEvent->id,
                    'top_confidence' => $topMatch['confidence'],
                ]);

                // Update merchant metadata
                $merchant = $this->receiptEvent->target;
                if ($merchant) {
                    $metadata = $merchant->metadata ?? [];
                    $metadata['is_matched'] = false;
                    $metadata['match_attempts'] = ($metadata['match_attempts'] ?? 0) + 1;
                    $metadata['last_match_attempt_at'] = now()->toIso8601String();
                    $merchant->update(['metadata' => $metadata]);
                }
            }
        } catch (Exception $e) {
            Log::error('Receipt: Transaction matching failed', [
                'receipt_id' => $this->receiptEvent->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        return 'match_receipt_' . $this->receiptEvent->id;
    }
}
