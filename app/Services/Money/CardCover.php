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
 * The buffer pot is the one pot with metadata.money.role = card_buffer. What a card owes is its
 * spending since its last repayment (or the start of the month when none is found). Amounts are
 * integer pence. Nothing here moves money.
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
     * @return array<string, mixed>
     */
    private function card(string $userId, EventObject $card, CarbonImmutable $now): array
    {
        $repayment = Event::where('actor_id', $card->id)->where('action', SpendingScope::BANK_IN)
            ->where('time', '<=', $now)->orderByDesc('time')->limit(30)->get()
            ->first(fn (Event $event): bool => $this->scope->isCardRepayment($event));
        $since = $repayment ? CarbonImmutable::instance($repayment->time) : $now->startOfMonth();

        $entries = $this->scope->entries($userId, $since, $now)
            ->where('account_id', (string) $card->id);

        $owed = max(0, (int) $entries->sum('amount'));
        $buffered = max(0, (int) $entries->filter(fn (array $entry): bool => in_array($entry['buffer'] ?? null, ['requested', 'linked'], true))->sum('amount'));

        return [
            'id' => (string) $card->id,
            'name' => $card->title,
            'since' => $since->toDateString(),
            'owed' => $owed,
            'buffered' => min($owed, $buffered),
            'transactions' => $entries->count(),
        ];
    }
}
