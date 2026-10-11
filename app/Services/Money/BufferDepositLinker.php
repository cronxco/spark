<?php

namespace App\Services\Money;

use App\Models\Event;
use App\Models\Relationship;
use Carbon\CarbonImmutable;

/**
 * Once Monzo reports the pot transfer made by "Add to buffer", links each requested card
 * transaction to it with a funded_by relationship (value = that transaction's amount) and sets
 * its buffer status to linked. Matches on destination pot, total amount and time window.
 */
class BufferDepositLinker
{
    /**
     * @return int Number of card transactions linked
     */
    public function link(Event $transfer): int
    {
        if ($transfer->service !== 'monzo' || $transfer->action !== 'pot_transfer_to' || ! $transfer->integration) {
            return 0;
        }

        $userId = (string) $transfer->integration->user_id;
        $window = (int) config('money.buffer.link_window_hours', 48);
        $time = CarbonImmutable::instance($transfer->time);

        $candidates = Event::forUser($userId)
            ->where('service', 'gocardless')
            ->whereNotNull('event_metadata->buffer')
            ->where('event_metadata->buffer->status', 'requested')
            ->where('event_metadata->buffer->pot_id', (string) $transfer->target_id)
            ->get()
            ->filter(function (Event $event) use ($time, $window): bool {
                $requested = CarbonImmutable::parse($event->event_metadata['buffer']['requested_at'] ?? $event->time);

                return $time->between($requested->subMinutes(5), $requested->addHours($window));
            });

        foreach ($candidates->groupBy(fn (Event $event): string => (string) $event->event_metadata['buffer']['dedupe_id']) as $batch) {
            if ((int) $batch->sum(fn (Event $event): int => (int) $event->event_metadata['buffer']['amount']) !== (int) $transfer->value) {
                continue;
            }

            foreach ($batch as $event) {
                Relationship::createRelationship([
                    'user_id' => $userId,
                    'from_type' => Event::class,
                    'from_id' => $event->id,
                    'to_type' => Event::class,
                    'to_id' => $transfer->id,
                    'type' => 'funded_by',
                    'value' => (int) $event->event_metadata['buffer']['amount'],
                    'value_multiplier' => 100,
                    'value_unit' => 'GBP',
                    'metadata' => ['auto_linked' => true, 'detection_strategy' => 'buffer_deposit'],
                ]);

                $metadata = $event->event_metadata;
                $metadata['buffer']['status'] = 'linked';
                $metadata['buffer']['transfer_event_id'] = (string) $transfer->id;
                $event->update(['event_metadata' => $metadata]);
            }

            return $batch->count();
        }

        return 0;
    }
}
