<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\TaskPipeline\BaseTaskJob;
use Illuminate\Support\Facades\Log;

class MatchReceiptToTransactionTask extends BaseTaskJob
{
    /**
     * Execute the receipt-to-transaction matching task
     */
    protected function execute(): void
    {
        Log::info('Receipt: Starting transaction matching via TaskPipeline', [
            'receipt_id' => $this->model->id,
            'merchant' => $this->model->target->title ?? 'unknown',
            'amount' => $this->model->value,
        ]);

        $matcher = new ReceiptTransactionMatcher;
        $candidates = $matcher->findCandidateMatches($this->model);

        if ($candidates->isEmpty()) {
            Log::info('Receipt: No transaction candidates found', [
                'receipt_id' => $this->model->id,
            ]);

            // Update merchant metadata to indicate no match found yet
            $merchant = $this->model->target;
            if ($merchant) {
                $metadata = $merchant->metadata ?? [];
                $metadata['is_matched'] = false;
                $metadata['match_attempts'] = ($metadata['match_attempts'] ?? 0) + 1;
                $metadata['last_match_attempt_at'] = now()->toIso8601String();
                $merchant->withoutEvents(function () use ($merchant, $metadata) {
                    $merchant->update(['metadata' => $metadata]);
                });
            }

            return;
        }

        $topMatch = $candidates->first();
        $autoMatchThreshold = config('services.receipt.auto_match_threshold', 0.8);
        $reviewThreshold = config('services.receipt.review_threshold', 0.5);

        if ($topMatch['confidence'] >= $autoMatchThreshold) {
            // Auto-match: Create relationship
            $matcher->createReceiptRelationship(
                $this->model,
                $topMatch['transaction'],
                $topMatch['confidence'],
                'automatic'
            );

            Log::info('Receipt: Auto-matched to transaction via TaskPipeline', [
                'receipt_id' => $this->model->id,
                'transaction_id' => $topMatch['transaction']->id,
                'confidence' => $topMatch['confidence'],
            ]);
        } elseif ($topMatch['confidence'] >= $reviewThreshold) {
            // Medium confidence: Flag for review
            $matcher->flagForReview($this->model, $candidates->take(3));

            Log::info('Receipt: Flagged for manual review', [
                'receipt_id' => $this->model->id,
                'top_confidence' => $topMatch['confidence'],
                'candidate_count' => $candidates->count(),
            ]);
        } else {
            // Low confidence: Do nothing, just log
            Log::info('Receipt: Low confidence matches only', [
                'receipt_id' => $this->model->id,
                'top_confidence' => $topMatch['confidence'],
            ]);

            // Update merchant metadata
            $merchant = $this->model->target;
            if ($merchant) {
                $metadata = $merchant->metadata ?? [];
                $metadata['is_matched'] = false;
                $metadata['match_attempts'] = ($metadata['match_attempts'] ?? 0) + 1;
                $metadata['last_match_attempt_at'] = now()->toIso8601String();
                $metadata['top_confidence'] = $topMatch['confidence'];
                $merchant->withoutEvents(function () use ($merchant, $metadata) {
                    $merchant->update(['metadata' => $metadata]);
                });
            }
        }
    }
}
