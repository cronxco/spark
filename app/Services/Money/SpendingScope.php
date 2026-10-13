<?php

namespace App\Services\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single definition of "spending" used by the Plan tab, budgets, Flint and the mobile API.
 *
 * Included: Monzo card payments, cash withdrawals included (refunds as negatives, declined
 * excluded), and open-banking payments on credit cards and current accounts. An inbound
 * open-banking line counts only as a refund on a credit card. Everything else is movement of
 * money, not spending: pot transfers, direct debits, transfers, salary and other income, fees,
 * interest, Flex, and card repayments. An open-banking line on either side of a payment_for
 * (card repayment) or transferred_to (own-account transfer) link is always excluded; the
 * repayment phrases in config are a fallback for lines nothing has linked yet. Computed at
 * query time; nothing is stored.
 *
 * Amounts are signed integer pence: spending is positive, refunds negative.
 */
class SpendingScope
{
    public const MONZO_OUT = 'card_payment_to';

    public const MONZO_REFUND = 'card_refund_from';

    public const BANK_OUT = 'payment_to';

    public const BANK_IN = 'payment_from';

    /** Days after an authorisation in which a booked line can settle it. */
    public const PENDING_SETTLEMENT_DAYS = 5;

    /**
     * @param  array{account?: string, category?: string, person?: string, budget?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(string $userId, CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection
    {
        $creditCards = $this->creditCardIds($userId);
        $spendingAccounts = $this->spendingAccountIds($userId);

        $entries = $this->query($userId, $from, $to, $spendingAccounts)
            ->with(['actor', 'target', 'tags'])
            ->orderByDesc('time')
            ->get()
            ->filter(fn (Event $event): bool => $this->counts($event, $creditCards))
            ->values();

        $pendingAmounts = $this->pendingAmounts($userId, $entries, $to);
        $entries = $entries->filter(fn (Event $event): bool => ! array_key_exists((string) $event->id, $pendingAmounts) || $pendingAmounts[(string) $event->id] > 0)->values();

        $overrides = $this->budgetOverrides($userId, $entries->pluck('id')->all());

        return $entries
            ->map(fn (Event $event): array => $this->entry($event, $overrides[(string) $event->id] ?? null, $pendingAmounts[(string) $event->id] ?? null))
            ->filter(fn (array $entry): bool => $this->matches($entry, $filters))
            ->values();
    }

    /**
     * Whether an inbound credit-card line pays the card off, by its wording or a payment_for link.
     */
    public function isCardRepayment(Event $event): bool
    {
        $description = strtoupper((string) ($event->event_metadata['description'] ?? ''));
        foreach ((array) config('money.repayment_phrases', []) as $phrase) {
            if ($description !== '' && str_contains($description, strtoupper((string) $phrase))) {
                return true;
            }
        }

        return $this->isLinkedMovement($event);
    }

    /**
     * What each open-banking pending authorisation still adds to spending, in pence, by event id.
     *
     * A pending line is an authorisation, not a purchase. Booked lines on the same account and
     * direction, from the same counterparty, booked on or after the authorisation within
     * PENDING_SETTLEMENT_DAYS, settle it. One authorisation can settle as several purchases, each
     * booked line settling at most one authorisation, so only the unsettled remainder counts. An
     * authorisation that is fully settled, or older than `money.pending_expiry_days`, counts as 0.
     * Nothing is stored; the events keep their history.
     *
     * @param  Collection<int, Event>  $events
     * @return array<string, int>
     */
    private function pendingAmounts(string $userId, Collection $events, CarbonInterface $to): array
    {
        $pending = $events->filter(fn (Event $event): bool => $this->isPending($event))
            ->sortBy(fn (Event $event): int => $event->time->getTimestamp())->values();
        if ($pending->isEmpty()) {
            return [];
        }

        $window = self::PENDING_SETTLEMENT_DAYS;
        $booked = Event::forUser($userId)->where('service', 'gocardless')
            ->whereIn('action', $pending->pluck('action')->unique()->all())
            ->whereIn('actor_id', $pending->pluck('actor_id')->unique()->all())
            ->where('event_metadata->transaction_status', 'booked')
            ->whereBetween('time', [$pending->first()->time->copy()->subDay()->startOfDay(), $pending->last()->time->copy()->addDays($window)->endOfDay()])
            ->orderBy('time')->get();

        $expiry = (int) config('money.pending_expiry_days', 30);
        $claimed = [];
        $amounts = [];
        foreach ($pending as $event) {
            $remaining = (int) $event->value;
            if ($event->time->lt(CarbonImmutable::instance($to)->subDays($expiry))) {
                $amounts[(string) $event->id] = 0;

                continue;
            }

            $pendingDay = $this->bookingDay($event);
            $candidates = $booked->filter(fn (Event $line): bool => ! isset($claimed[(string) $line->id])
                && $line->actor_id === $event->actor_id
                && $line->action === $event->action
                && $line->value_unit === $event->value_unit
                && $this->bookingDay($line) >= $pendingDay
                && $line->time->between($event->time->copy()->subDay(), $event->time->copy()->addDays($window)->endOfDay())
                && $this->sameCounterparty($event, $line));

            foreach ($candidates as $line) {
                if ((int) $line->value <= $remaining) {
                    $remaining -= (int) $line->value;
                    $claimed[(string) $line->id] = true;
                }
            }
            $amounts[(string) $event->id] = $remaining;
        }

        return $amounts;
    }

    private function isPending(Event $event): bool
    {
        return $event->service === 'gocardless' && ($event->event_metadata['transaction_status'] ?? null) === 'pending';
    }

    private function bookingDay(Event $event): string
    {
        return (string) ($event->event_metadata['booking_date'] ?? $event->time->toDateString());
    }

    private function sameCounterparty(Event $pending, Event $booked): bool
    {
        $name = fn (Event $event): string => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) ($event->event_metadata['raw']['creditorName']
            ?? $event->event_metadata['raw']['debtorName'] ?? $event->event_metadata['description'] ?? '')));
        [$left, $right] = [$name($pending), $name($booked)];

        return $left !== '' && $right !== '' && (str_starts_with($left, $right) || str_starts_with($right, $left));
    }

    /**
     * @param  list<string>  $spendingAccounts
     * @return Builder<Event>
     */
    private function query(string $userId, CarbonInterface $from, CarbonInterface $to, array $spendingAccounts): Builder
    {
        return Event::forUser($userId)
            ->whereBetween('time', [$from, $to])
            ->where(function (Builder $query) use ($spendingAccounts): void {
                $query->where(function (Builder $monzo): void {
                    $monzo->where('service', 'monzo')
                        ->whereIn('action', [self::MONZO_OUT, self::MONZO_REFUND]);
                });

                if ($spendingAccounts !== []) {
                    $query->orWhere(function (Builder $bank) use ($spendingAccounts): void {
                        $bank->where('service', 'gocardless')
                            ->whereIn('action', [self::BANK_OUT, self::BANK_IN])
                            ->whereIn('actor_id', $spendingAccounts);
                    });
                }
            });
    }

    /**
     * @param  list<string>  $creditCards
     */
    private function counts(Event $event, array $creditCards): bool
    {
        $meta = $event->event_metadata ?? [];

        if ($event->service === 'monzo') {
            return ($meta['include_in_spending'] ?? null) !== false;
        }

        if ($event->action === self::BANK_IN && ! in_array((string) $event->actor_id, $creditCards, true)) {
            return false;
        }

        if ($this->isLinkedMovement($event)) {
            return false;
        }

        $description = strtoupper((string) ($meta['description'] ?? ''));
        foreach ((array) config('money.repayment_phrases', []) as $phrase) {
            if ($description !== '' && str_contains($description, strtoupper((string) $phrase))) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the line is on either side of a card repayment or an own-account transfer.
     */
    private function isLinkedMovement(Event $event): bool
    {
        return Relationship::whereIn('type', ['payment_for', 'transferred_to'])
            ->where(function ($query) use ($event): void {
                $query->where(fn ($q) => $q->where('from_type', Event::class)->where('from_id', $event->id))
                    ->orWhere(fn ($q) => $q->where('to_type', Event::class)->where('to_id', $event->id));
            })
            ->exists();
    }

    /**
     * Open-banking accounts whose payments are spending: everything except savings, loans and investments.
     *
     * @return list<string>
     */
    private function spendingAccountIds(string $userId): array
    {
        return EventObject::where('user_id', $userId)
            ->where('concept', 'account')
            ->where('type', 'bank_account')
            ->get()
            ->reject(fn (EventObject $account): bool => in_array($account->metadata['account_type'] ?? null, ['savings_account', 'savings', 'loan', 'investment_account'], true))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function creditCardIds(string $userId): array
    {
        return EventObject::where('user_id', $userId)
            ->where('concept', 'account')
            ->where('type', 'bank_account')
            ->get()
            ->filter(fn (EventObject $account): bool => ($account->metadata['account_type'] ?? null) === 'credit_card')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @param  list<string>  $eventIds
     * @return array<string, string> Event id => budget object id
     */
    private function budgetOverrides(string $userId, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return Relationship::where('user_id', $userId)
            ->where('type', 'part_of')
            ->where('from_type', Event::class)
            ->whereIn('from_id', $eventIds)
            ->where('to_type', EventObject::class)
            ->whereIn('to_id', EventObject::where('user_id', $userId)->where('concept', 'budget')->select('id'))
            ->pluck('to_id', 'from_id')
            ->mapWithKeys(fn ($to, $from): array => [(string) $from => (string) $to])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Event $event, ?string $budgetId, ?int $pendingRemaining = null): array
    {
        $refund = in_array($event->action, [self::MONZO_REFUND, self::BANK_IN], true);
        $meta = $event->event_metadata ?? [];
        $categories = $event->tags->where('type', 'transaction_category');
        $category = $categories->first()?->name;
        $pending = $event->service === 'gocardless'
            ? ($meta['transaction_status'] ?? null) === 'pending'
            : (bool) ($meta['amount_is_pending'] ?? false);
        $amount = $pendingRemaining ?? (int) $event->value;

        return [
            'id' => (string) $event->id,
            'time' => $event->time,
            'amount' => $refund ? -$amount : $amount,
            'merchant' => (string) ($event->target?->title ?: ($meta['description'] ?? 'Unknown')),
            'category' => $category ? $this->normaliseCategory((string) $category) : 'uncategorised',
            'account_id' => (string) $event->actor_id,
            'account' => (string) ($event->actor?->title ?? ''),
            'person' => $event->tags->where('type', 'person')->first()?->name,
            'source' => $event->service,
            'pending' => $pending,
            'pending_authorised' => $pendingRemaining !== null ? (int) $event->value : null,
            'booking_date' => $meta['booking_date'] ?? null,
            'value_date' => $meta['value_date'] ?? null,
            'budget_id' => $budgetId,
            'buffer' => $meta['buffer']['status'] ?? null,
        ];
    }

    private function normaliseCategory(string $category): string
    {
        return str_replace([' ', '-'], '_', mb_strtolower(trim($category)));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{account?: string, category?: string, person?: string, budget?: string}  $filters
     */
    private function matches(array $entry, array $filters): bool
    {
        foreach (['account' => 'account_id', 'category' => 'category', 'budget' => 'budget_id'] as $filter => $key) {
            if (! empty($filters[$filter]) && (string) $entry[$key] !== (string) $filters[$filter]) {
                return false;
            }
        }

        return empty($filters['person']) || mb_strtolower((string) $entry['person']) === mb_strtolower((string) $filters['person']);
    }
}
