<?php

namespace App\Console\Commands;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\Receipt\ReviewUnmatchedReceiptJob;
use App\Models\Event;
use App\Services\Receipt\ReceiptMatchState;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillReceiptMatches extends Command
{
    protected $signature = 'receipt-matching:backfill
        {--dispatch : Queue review-only matching attempts; without this option the command is read-only}
        {--limit=25 : Maximum receipts in one batch}
        {--recent-days= : Only receipts from the last N days}
        {--retry-after-days= : Revisit previously swept receipts only after N days}';

    protected $description = 'Preview or queue bounded, review-only matching for unlinked receipts';

    public function handle(ReceiptTransactionMatcher $matcher): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 500) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }
        $retryDays = $this->option('retry-after-days') !== null ? (int) $this->option('retry-after-days') : null;
        $recentDays = $this->option('recent-days') !== null ? (int) $this->option('recent-days') : null;
        if (($retryDays !== null && $retryDays < 1) || ($recentDays !== null && $recentDays < 1)) {
            $this->error('Day options must be positive.');

            return self::FAILURE;
        }

        $query = Event::query()->where('service', 'receipt')->where('action', 'had_receipt_from')
            ->whereNotIn('id', ReceiptMatchState::links()->select('from_id'))
            ->where(function ($query): void {
                $query->whereNull('event_metadata->receipt_matching->status')
                    ->orWhereNotIn('event_metadata->receipt_matching->status', ['suggestions', 'no_match', 'dismissed', 'searching'])
                    ->orWhere(function ($query): void {
                        $query->where('event_metadata->receipt_matching->status', 'searching')
                            ->where('event_metadata->receipt_matching->attempted_at', '<', now()->subHour()->toIso8601String());
                    });
            })
            ->where(function ($query) use ($retryDays): void {
                $query->whereNull('event_metadata->receipt_matching->backfill_attempted_at');
                if ($retryDays !== null) {
                    $query->orWhere('event_metadata->receipt_matching->backfill_attempted_at', '<=', now()->subDays($retryDays)->toIso8601String());
                }
            });
        if ($recentDays !== null) {
            $query->where('time', '>=', now()->subDays($recentDays));
        }
        $receipts = $query->with(['target', 'integration'])->orderByDesc('time')->limit($limit)->get();
        $this->info("Selected {$receipts->count()} unlinked receipts (limit {$limit}).");

        if (! $this->option('dispatch')) {
            $bands = ['none' => 0, 'below 0.8' => 0, '0.8 or above' => 0];
            foreach ($receipts as $receipt) {
                $best = $matcher->findCandidateMatches($receipt)->first()['confidence'] ?? null;
                $band = $best === null ? 'none' : ($best >= 0.8 ? '0.8 or above' : 'below 0.8');
                $bands[$band]++;
            }
            $this->table(['Candidate band', 'Receipts'], collect($bands)->map(fn ($count, $band) => [$band, $count])->values()->all());
            $this->info('Preview only. Use --dispatch to queue review-only attempts.');

            return self::SUCCESS;
        }

        $batchId = (string) Str::uuid();
        foreach ($receipts as $receipt) {
            ReceiptMatchState::update($receipt, [
                'status' => 'searching',
                'backfill_batch_id' => $batchId,
                'backfill_attempted_at' => now()->toIso8601String(),
            ]);
            ReviewUnmatchedReceiptJob::dispatch((string) $receipt->id, $batchId);
        }
        $this->info("Queued {$receipts->count()} review-only attempts in batch {$batchId}.");

        return self::SUCCESS;
    }
}
