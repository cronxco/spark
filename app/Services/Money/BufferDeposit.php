<?php

namespace App\Services\Money;

use App\Integrations\Monzo\MonzoPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * "Add to buffer": moves the amount of selected credit-card transactions from the buffer pot's
 * Monzo account into the buffer pot, and marks each transaction in event_metadata.buffer.
 *
 * Guardrails: switched off until enabled in config, credit-card spending only, never already
 * buffered, one fixed destination (the pot with role card_buffer), a per-action cap, and a
 * confirmed total that must match what is about to move. The dedupe id is derived from the
 * transactions so a retry or double tap cannot move money twice. This only ever runs from an
 * explicit user action.
 *
 * Statuses in event_metadata.buffer.status: requested, linked (set by BufferDepositLinker), failed.
 */
class BufferDeposit
{
    public function __construct(private SpendingScope $scope, private CardCover $cover) {}

    /**
     * What would move, without moving anything.
     *
     * @param  list<string>  $eventIds
     * @return array{total: int, pot: array{id: string, name: string}, source_account_id: string, transactions: list<array<string, mixed>>, dedupe_id: string}
     *
     * @throws RuntimeException with a message fit to show the user
     */
    public function prepare(string $userId, array $eventIds): array
    {
        if (! (bool) config('money.buffer.enabled')) {
            throw new RuntimeException('Add to buffer is not switched on yet.');
        }

        $eventIds = array_values(array_unique(array_map('strval', $eventIds)));
        if ($eventIds === []) {
            throw new RuntimeException('Pick at least one credit-card transaction.');
        }

        $pot = $this->cover->bufferPot($userId);
        if ($pot === null) {
            throw new RuntimeException('Choose a buffer pot first.');
        }
        $potId = (string) ($pot->metadata['pot_id'] ?? '');
        $source = (string) ($pot->metadata['monzo']['current_account_id'] ?? '');
        if ($potId === '' || $source === '') {
            throw new RuntimeException('Spark does not know which Monzo account feeds that pot yet. Refresh the Monzo pots and try again.');
        }

        $events = Event::forUser($userId)->whereIn('id', $eventIds)->get();
        if ($events->count() !== count($eventIds)) {
            throw new RuntimeException('Some of those transactions could not be found.');
        }

        $from = CarbonImmutable::instance($events->min('time'))->startOfDay();
        $to = CarbonImmutable::instance($events->max('time'))->endOfDay();
        $entries = $this->scope->entries($userId, $from, $to)->keyBy('id');

        $transactions = [];
        foreach ($events as $event) {
            $entry = $entries->get((string) $event->id);
            if ($entry === null || $entry['source'] !== 'gocardless' || $entry['amount'] <= 0) {
                throw new RuntimeException('Only credit-card spending can be added to the buffer.');
            }
            if (in_array($entry['buffer'], ['requested', 'linked'], true)) {
                throw new RuntimeException("{$entry['merchant']} is already in the buffer.");
            }
            $transactions[] = $entry;
        }

        $total = (int) array_sum(array_column($transactions, 'amount'));
        $cap = (int) config('money.buffer.cap_pence');
        if ($total > $cap) {
            throw new RuntimeException('That is more than the £'.number_format($cap / 100).' limit for one go.');
        }

        return [
            'total' => $total,
            'pot' => ['id' => (string) $pot->id, 'name' => $pot->title],
            'source_account_id' => $source,
            'transactions' => $transactions,
            'dedupe_id' => $this->dedupeId($events->all(), $total),
        ];
    }

    /**
     * Move the money. $confirmedTotal must be the total the user saw on the confirmation.
     *
     * @param  list<string>  $eventIds
     * @return array{total: int, status: string}
     *
     * @throws RuntimeException with a message fit to show the user
     */
    public function request(string $userId, array $eventIds, int $confirmedTotal): array
    {
        $plan = $this->prepare($userId, $eventIds);
        if ($plan['total'] !== $confirmedTotal) {
            throw new RuntimeException('The total changed since you reviewed it. Review it again.');
        }

        $pot = EventObject::where('user_id', $userId)->findOrFail($plan['pot']['id']);
        $events = Event::forUser($userId)->whereIn('id', array_column($plan['transactions'], 'id'))->get();
        $this->mark($events, 'requested', $plan);

        try {
            $result = (new MonzoPlugin)->depositIntoPot(
                $this->monzoIntegration($userId),
                (string) $pot->metadata['pot_id'],
                $plan['source_account_id'],
                $plan['total'],
                $plan['dedupe_id'],
            );
        } catch (Throwable $e) {
            // The outcome is unknown, so leave the transactions as requested. Retrying uses the same dedupe id.
            $this->mark($events, 'requested', $plan, ['error' => $e->getMessage(), 'uncertain' => true]);

            throw new RuntimeException('Monzo did not answer, so Spark cannot tell whether the money moved. Check Monzo before trying again.');
        }

        if (! $result['ok']) {
            $this->mark($events, 'failed', $plan, ['error' => $result['error']]);

            throw new RuntimeException('Monzo refused it: '.$result['error']);
        }

        return ['total' => $plan['total'], 'status' => 'requested'];
    }

    /**
     * @param  iterable<Event>  $events
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $extra
     */
    private function mark(iterable $events, string $status, array $plan, array $extra = []): void
    {
        $amounts = array_column($plan['transactions'], 'amount', 'id');
        foreach ($events as $event) {
            $metadata = $event->event_metadata ?? [];
            $previous = $metadata['buffer'] ?? [];
            $metadata['buffer'] = array_filter([
                'status' => $status,
                'dedupe_id' => $plan['dedupe_id'],
                'amount' => $amounts[(string) $event->id] ?? null,
                'pot_id' => $plan['pot']['id'],
                'requested_at' => $previous['requested_at'] ?? now()->toIso8601String(),
                'retry' => (int) ($previous['retry'] ?? 0) + ($status === 'failed' ? 1 : 0),
            ] + $extra, fn ($value) => $value !== null);
            $event->update(['event_metadata' => $metadata]);
        }
    }

    /**
     * Monzo remembers a dedupe id, so a retry must reuse it, but a retry after a refusal needs a fresh one.
     *
     * @param  list<Event>  $events
     */
    private function dedupeId(array $events, int $total): string
    {
        $ids = collect($events)->map(fn (Event $event): string => (string) $event->id)->sort()->values()->all();
        $previous = collect($events)->map(fn (Event $event): int => (int) ($event->event_metadata['buffer']['retry'] ?? 0))->max();

        return 'spark-buffer-'.substr(sha1(implode(',', $ids).'|'.$total), 0, 24).'-'.$previous;
    }

    private function monzoIntegration(string $userId): Integration
    {
        $integration = Integration::where('user_id', $userId)->where('service', 'monzo')->where('instance_type', 'accounts')->first();
        if ($integration === null) {
            throw new RuntimeException('Monzo is not connected.');
        }

        return $integration;
    }
}
