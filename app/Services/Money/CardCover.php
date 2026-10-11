<?php

namespace App\Services\Money;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Read-only: is there enough in the card buffer pot to pay off what is owed on each credit card?
 *
 * The buffer pot is the one pot with metadata.money.role = card_buffer. A card with billing days set
 * (metadata.money.billing) owes its unpaid statement plus spending since it closed. Without them it
 * owes spending since its last repayment, or since the start of the month when none is found.
 * Amounts are integer pence. Nothing here moves money.
 */
class CardCover
{
    public const ROLE = 'card_buffer';

    public function __construct(private SpendingScope $scope) {}

    /**
     * @return array{
     *     pot: ?array{id: string, name: string, balance: int},
     *     cards: list<array<string, mixed>>,
     *     owed: int,
     *     buffered: int,
     *     unbuffered: int,
     *     status: string,
     *     shortfall: int
     * }
     */
    public function build(string $userId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $pot = $this->bufferPot($userId);
        $balance = $pot ? (int) ((new FinancialPlugin)->getLatestBalancesForAccounts(collect([$pot]))->get($pot->id)?->value ?? 0) : 0;

        $cards = EventObject::where('user_id', $userId)->where('concept', 'account')->where('type', 'bank_account')->get()
            ->filter(fn (EventObject $account): bool => ($account->metadata['account_type'] ?? null) === 'credit_card')
            ->map(fn (EventObject $card): array => $this->card($userId, $card, $now))
            ->sortByDesc('owed')->values();

        $owed = (int) $cards->sum('owed');
        $buffered = (int) $cards->sum('buffered');
        $unbuffered = $owed - $buffered;
        $shortfall = max(0, $owed - $balance);

        return [
            'pot' => $pot ? ['id' => (string) $pot->id, 'name' => $pot->title, 'balance' => $balance] : null,
            'cards' => $cards->all(),
            'owed' => $owed,
            'buffered' => $buffered,
            'unbuffered' => $unbuffered,
            'status' => match (true) {
                $pot === null => 'no_buffer',
                $owed <= 0 || $shortfall === 0 => 'covered',
                default => 'short',
            },
            'shortfall' => $pot === null ? $owed : $shortfall,
        ];
    }

    /**
     * Make one pot the card buffer. Only one pot holds the role at a time.
     */
    public function setBufferPot(string $userId, ?string $potId): void
    {
        $pots = EventObject::where('user_id', $userId)->where('concept', 'account')->where('type', 'monzo_pot')->get();
        if ($potId !== null && ! $pots->contains(fn (EventObject $pot): bool => (string) $pot->id === $potId)) {
            throw new InvalidArgumentException('That is not one of your Monzo pots.');
        }

        foreach ($pots as $pot) {
            $metadata = $pot->metadata ?? [];
            $isTarget = (string) $pot->id === $potId;
            if ($isTarget) {
                $metadata['money']['role'] = self::ROLE;
            } elseif (($metadata['money']['role'] ?? null) === self::ROLE) {
                unset($metadata['money']['role']);
                if (($metadata['money'] ?? []) === []) {
                    unset($metadata['money']);
                }
            } else {
                continue;
            }
            $pot->update(['metadata' => $metadata]);
        }
    }

    public function bufferPot(string $userId): ?EventObject
    {
        return EventObject::where('user_id', $userId)->where('concept', 'account')->where('type', 'monzo_pot')->get()
            ->first(fn (EventObject $pot): bool => ($pot->metadata['money']['role'] ?? null) === self::ROLE);
    }

    /**
     * Set the day of the month a card's statement closes and the day its payment is taken.
     */
    public function setBilling(string $userId, string $cardId, ?int $statementDay, ?int $paymentDay): void
    {
        $card = EventObject::where('user_id', $userId)->where('concept', 'account')->where('type', 'bank_account')->find($cardId);
        if (! $card || ($card->metadata['account_type'] ?? null) !== 'credit_card') {
            throw new InvalidArgumentException('That is not one of your credit cards.');
        }
        foreach ([$statementDay, $paymentDay] as $day) {
            if ($day !== null && ($day < 1 || $day > 31)) {
                throw new InvalidArgumentException('Days run from 1 to 31.');
            }
        }

        $metadata = $card->metadata ?? [];
        if ($statementDay === null) {
            unset($metadata['money']['billing']);
            if (($metadata['money'] ?? []) === []) {
                unset($metadata['money']);
            }
        } else {
            $metadata['money']['billing'] = ['statement_day' => $statementDay, 'payment_day' => $paymentDay];
        }
        $card->update(['metadata' => $metadata]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $userId, EventObject $card, CarbonImmutable $now): array
    {
        $repayment = Event::where('actor_id', $card->id)->where('action', SpendingScope::BANK_IN)
            ->where('time', '<=', $now)->orderByDesc('time')->limit(30)->get()
            ->first(fn (Event $event): bool => $this->scope->isCardRepayment($event));
        $statementDay = (int) ($card->metadata['money']['billing']['statement_day'] ?? 0);
        $paymentDay = (int) ($card->metadata['money']['billing']['payment_day'] ?? 0);

        $closed = null;
        $due = null;
        if ($statementDay > 0) {
            $closed = $this->lastClose($now, $statementDay);
            $paid = $repayment !== null && CarbonImmutable::instance($repayment->time)->gt($closed->endOfDay());
            $since = $paid ? $closed->addDay()->startOfDay() : $this->lastClose($closed->subDay(), $statementDay)->addDay()->startOfDay();
            $due = ! $paid && $paymentDay > 0 ? $this->paymentDate($closed, $paymentDay) : null;
        } else {
            $paid = null;
            $since = $repayment ? CarbonImmutable::instance($repayment->time) : $now->startOfMonth();
        }

        $entries = $this->scope->entries($userId, $since, $now)
            ->where('account_id', (string) $card->id);

        $owed = max(0, (int) $entries->sum('amount'));
        $buffered = max(0, (int) $entries->filter(fn (array $entry): bool => in_array($entry['buffer'] ?? null, ['requested', 'linked'], true))->sum('amount'));
        $statementOwed = $closed !== null && ! $paid
            ? max(0, (int) $entries->filter(fn (array $entry): bool => CarbonImmutable::instance($entry['time'])->lte($closed->endOfDay()))->sum('amount'))
            : 0;

        return [
            'id' => (string) $card->id,
            'name' => $card->title,
            'since' => $since->toDateString(),
            'owed' => $owed,
            'buffered' => min($owed, $buffered),
            'transactions' => $entries->count(),
            'statement_day' => $statementDay ?: null,
            'payment_day' => $paymentDay ?: null,
            'statement_closed' => $closed?->toDateString(),
            'statement_owed' => $statementOwed,
            'statement_due' => $due?->toDateString(),
        ];
    }

    /**
     * The latest statement close on or before the given day, clamped to short months.
     */
    private function lastClose(CarbonImmutable $on, int $day): CarbonImmutable
    {
        $on = $on->startOfDay();
        $candidate = $on->startOfMonth()->addDays(min($day, $on->daysInMonth) - 1);
        if ($candidate->gt($on)) {
            $previous = $on->startOfMonth()->subMonthNoOverflow();
            $candidate = $previous->addDays(min($day, $previous->daysInMonth) - 1);
        }

        return $candidate;
    }

    /**
     * The first payment day after a statement closes.
     */
    private function paymentDate(CarbonImmutable $closed, int $day): CarbonImmutable
    {
        $candidate = $closed->startOfMonth()->addDays(min($day, $closed->daysInMonth) - 1);
        if ($candidate->gt($closed)) {
            return $candidate;
        }
        $next = $closed->startOfMonth()->addMonthNoOverflow();

        return $next->addDays(min($day, $next->daysInMonth) - 1);
    }
}
