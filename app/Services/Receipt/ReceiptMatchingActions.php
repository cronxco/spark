<?php

namespace App\Services\Receipt;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReceiptMatchingActions
{
    public function retry(Event $receipt): void
    {
        $queued = DB::transaction(function () use ($receipt): bool {
            $locked = Event::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (ReceiptMatchState::isMatched($locked)) {
                throw new InvalidArgumentException('This receipt is already matched.');
            }
            $state = ReceiptMatchState::state($locked);
            if (($state['status'] ?? null) === 'searching'
                && ! empty($state['attempted_at'])
                && now()->diffInMinutes($state['attempted_at']) < 10) {
                return false;
            }
            ReceiptMatchState::update($locked, [
                'status' => 'searching',
                'attempted_at' => now()->toIso8601String(),
                'reason' => null,
                'candidates' => [],
            ]);

            return true;
        });

        if ($queued) {
            ProcessTaskPipelineJob::dispatch($receipt, 'manual', ['match_receipt_to_transaction'], true)
                ->onQueue('tasks');
        }
    }

    public function search(Event $receipt, ?string $term = null): Collection
    {
        $ownerId = $receipt->integration?->user_id;
        if (! $ownerId) {
            return new Collection;
        }
        $query = Event::forUser($ownerId)
            ->whereIn('service', ['monzo', 'gocardless'])
            ->where('domain', 'money')
            ->whereIn('action', ReceiptTransactionMatcher::TRANSACTION_ACTIONS)
            ->with('target');
        $term = trim($term ?? '');
        if ($term !== '') {
            $query->where(function ($query) use ($term): void {
                $query->whereHas('target', fn ($target) => $target->where('title', 'ilike', '%' . $term . '%'))
                    ->orWhereRaw('CAST(value AS TEXT) LIKE ?', ['%' . $term . '%']);
                if (preg_match('/^\d+(?:\.\d{1,2})?$/', $term)) {
                    $query->orWhere('value', (int) round((float) $term * 100));
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $term)) {
                    $query->orWhereDate('time', $term);
                }
            });
        } elseif ($receipt->time) {
            $query->whereBetween('time', [$receipt->time->copy()->subDays(30), $receipt->time->copy()->addDays(30)]);
        }

        return $query->orderByDesc('time')->limit(25)->get();
    }

    public function link(Event $receipt, Event $transaction): void
    {
        app(ReceiptTransactionMatcher::class)->createReceiptRelationship($receipt, $transaction, null, 'manual');
    }

    public function unlink(Event $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            Event::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            ReceiptMatchState::links()->where('from_id', $receipt->id)->delete();
            ReceiptMatchState::clear($receipt);
        });
    }

    public function markNoMatch(Event $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            $locked = Event::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (ReceiptMatchState::isMatched($locked)) {
                throw new InvalidArgumentException('Unlink this receipt before marking it unmatched.');
            }
            ReceiptMatchState::update($locked, [
                'status' => 'no_match',
                'reason' => 'confirmed_by_user',
                'candidates' => [],
                'dismissed_at' => now()->toIso8601String(),
            ]);
        });
    }
}
