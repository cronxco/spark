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
     * Record what the issuer printed for a statement. A recorded balance replaces the estimate for
     * that statement and is never changed by later imports.
     */
    public function recordStatement(string $userId, string $cardId, CarbonImmutable $closed, int $balance, ?CarbonImmutable $directDebit = null, ?CarbonImmutable $due = null): void
    {
        $card = EventObject::where('user_id', $userId)->where('concept', 'account')->where('type', 'bank_account')->find($cardId);
        if (! $card || ($card->metadata['account_type'] ?? null) !== 'credit_card') {
            throw new InvalidArgumentException('That is not one of your credit cards.');
        }
        if ($balance < 0) {
            throw new InvalidArgumentException('A statement balance cannot be negative.');
        }

        $metadata = $card->metadata ?? [];
        $metadata['money']['statements'][$closed->toDateString()] = [
            'balance' => $balance,
            'direct_debit' => $directDebit?->toDateString(),
            'due' => $due?->toDateString(),
        ];
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
        $previous = null;
        $directDebit = null;
        if ($statementDay > 0) {
            $closed = $this->lastClose($now, $statementDay);
            $previous = $this->lastClose($closed->subDay(), $statementDay);
            $paid = $repayment !== null && CarbonImmutable::instance($repayment->time)->gt($closed->endOfDay());
            $since = $paid ? $closed->addDay()->startOfDay() : $previous->addDay()->startOfDay();
            $directDebit = ! $paid && $paymentDay > 0 ? $this->paymentDate($closed, $paymentDay) : null;
        } else {
            $paid = null;
            $since = $repayment ? CarbonImmutable::instance($repayment->time) : $now->startOfMonth();
        }

        $recorded = $closed !== null ? ($card->metadata['money']['statements'][$closed->toDateString()] ?? null) : null;

        // Statement membership follows the date the issuer processed a line, which can trail the
        // transaction date, so look back a few days and sort lines by that date.
        $entries = $this->scope->entries($userId, $closed !== null ? $since->subDays(5) : $since, $now)
            ->where('account_id', (string) $card->id);
        if ($closed !== null) {
            $entries = $entries->filter(fn (array $entry): bool => $this->processedOn($entry, $closed) >= $since->toDateString())
                ->values();
        }

        $inStatement = $closed !== null && ! $paid
            ? $entries->filter(fn (array $entry): bool => $this->processedOn($entry, $closed) <= $closed->toDateString())
            : collect();
        $statementOwed = $closed !== null && ! $paid
            ? ($recorded !== null ? (int) $recorded['balance'] : max(0, (int) $inStatement->sum('amount')))
            : 0;
        $after = $closed !== null ? $entries->reject(fn (array $entry): bool => $inStatement->contains('id', $entry['id']))->values() : $entries;
        $sinceStatement = (int) $after->sum('amount');

        $owed = $closed !== null ? max(0, $statementOwed + $sinceStatement) : max(0, (int) $entries->sum('amount'));
        $buffered = max(0, (int) $entries->filter(fn (array $entry): bool => in_array($entry['buffer'] ?? null, ['requested', 'linked'], true))->sum('amount'));
        $confirmed = $recorded !== null && ! $paid;
        $directDebit = $confirmed && ! empty($recorded['direct_debit']) ? CarbonImmutable::parse($recorded['direct_debit']) : $directDebit;

        return [
            'id' => (string) $card->id,
            'name' => $card->title,
            'since' => $since->toDateString(),
            'owed' => $owed,
            'buffered' => min($owed, $buffered),
            'pending' => max(0, (int) $entries->where('pending', true)->sum('amount')),
            'statement_pending' => (int) $inStatement->where('pending', true)->sum('amount'),
            'transactions' => $entries->count(),
            'statement_day' => $statementDay ?: null,
            'payment_day' => $paymentDay ?: null,
            'statement_closed' => $closed?->toDateString(),
            'statement_owed' => $statementOwed,
            'statement_basis' => $closed === null || $paid ? null : ($confirmed ? 'confirmed' : 'estimate'),
            'spent_since_statement' => $closed !== null ? max(0, $sinceStatement) : null,
            'direct_debit' => $directDebit?->toDateString(),
            'payment_due' => $confirmed && ! empty($recorded['due']) ? $recorded['due'] : null,
            'statement_due' => $directDebit?->toDateString(),
        ];
    }

    /**
     * The day a line belongs to for statement purposes: the day the issuer processed it (value
     * date), else its booking date, else the day it happened. An authorisation the bank still
     * shows as pending is dated as if it settled SpendingScope::PENDING_SETTLEMENT_DAYS after it was
     * made, so one left open past a statement close is assumed billed on it.
     *
     * @param  array<string, mixed>  $entry
     */
    private function processedOn(array $entry, CarbonImmutable $closed): string
    {
        if ($entry['pending']) {
            return CarbonImmutable::parse((string) ($entry['booking_date']
                ?? CarbonImmutable::instance($entry['time'])->setTimezone($closed->getTimezone())->toDateString()))
                ->addDays(SpendingScope::PENDING_SETTLEMENT_DAYS)->toDateString();
        }

        return (string) ($entry['value_date'] ?? $entry['booking_date']
            ?? CarbonImmutable::instance($entry['time'])->setTimezone($closed->getTimezone())->toDateString());
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
