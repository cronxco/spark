<?php

namespace App\Services\Receipt;

use App\Models\Event;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Product state belongs to the receipt event; the link remains authoritative. */
class ReceiptMatchState
{
    public static function links(): Builder
    {
        return Relationship::query()
            ->where('type', 'receipt_for')
            ->where('from_type', Event::class)
            ->where('to_type', Event::class);
    }

    public static function link(Event $receipt): ?Relationship
    {
        return self::links()->where('from_id', $receipt->id)->first();
    }

    public static function isMatched(Event $receipt): bool
    {
        return self::link($receipt) !== null;
    }

    public static function state(Event $receipt): array
    {
        return $receipt->event_metadata['receipt_matching'] ?? [];
    }

    public static function status(Event $receipt): string
    {
        if (self::isMatched($receipt)) {
            return 'matched';
        }

        return self::state($receipt)['status'] ?? 'unmatched';
    }

    public static function candidates(Event $receipt): array
    {
        return self::state($receipt)['candidates'] ?? [];
    }

    public static function update(Event $receipt, array $changes): void
    {
        DB::transaction(function () use ($receipt, $changes): void {
            $locked = Event::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $metadata = $locked->event_metadata ?? [];
            $metadata['receipt_matching'] = array_replace($metadata['receipt_matching'] ?? [], $changes);
            $locked->withoutEvents(fn () => $locked->update(['event_metadata' => $metadata]));
            $receipt->refresh();
        });
    }

    public static function failIfPending(Event $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            $locked = Event::query()->whereKey($receipt->id)->lockForUpdate()->first();
            if (! $locked || self::isMatched($locked)
                || in_array(self::status($locked), ['no_match', 'dismissed', 'suggestions'], true)) {
                return;
            }
            self::update($locked, [
                'status' => 'failed',
                'reason' => 'matching_failed',
                'attempted_at' => now()->toIso8601String(),
            ]);
        });
    }

    public static function clear(Event $receipt, string $status = 'unmatched'): void
    {
        self::update($receipt, ['status' => $status, 'candidates' => [], 'reason' => null]);
    }
}
