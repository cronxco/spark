<?php

namespace App\Services\Flint;

use App\Models\Event;
use App\Services\Money\SpendingScope;
use App\Support\CalendarFlight;
use App\Support\KeyTakeaways;
use App\Support\MerchantName;
use Illuminate\Support\Str;

/**
 * Which layout a linked event card takes, and the facts that layout shows.
 *
 * Most linked events are daily metrics (sleep, readiness, steps) and keep the
 * compact metric card. One-off events get a card that says what they are
 * instead of a comparison with a baseline that doesn't fit them (design
 * language §7.3, Will, 5 Nov 2026):
 *
 * - `journey`: a flight from a flight tracker's calendar entry (route, local
 *   times, flight time).
 * - `calendar`: any other calendar entry (title and time span).
 * - `payment`: a card or bank payment or a direct debit (merchant, account,
 *   pending, and where it sits among the week's spending).
 * - `transfer`: money moving rather than being spent: bank transfers, direct
 *   credits, Monzo Me, pot moves, salary, and money into an open-banking
 *   account that isn't a credit card (direction, the other party, the account
 *   and pending; Will, 6 Nov 2026).
 * - `reading`: an article or newsletter issue (publication, its own headline,
 *   read time and up to four key takeaways).
 * - `metric`: everything else.
 */
class FlintEventCardDetails
{
    public const MAX_TAKEAWAYS = 4;

    /** Words a minute for the read-time estimate. */
    private const READING_SPEED = 230;

    /** Most payments the week strip draws. */
    private const WEEK_BARS = 10;

    private const PAYMENT_ACTIONS = [
        SpendingScope::MONZO_OUT,
        SpendingScope::BANK_OUT,
        SpendingScope::MONZO_REFUND,
        SpendingScope::BANK_IN,
        'direct_debit_to',
    ];

    /** Monzo actions that move money rather than spend it, and which way. */
    private const TRANSFER_DIRECTIONS = [
        'bank_transfer_from' => 'in',
        'bank_transfer_to' => 'out',
        'direct_credit_from' => 'in',
        'monzo_me_from' => 'in',
        'monzo_me_to' => 'out',
        'pot_withdrawal_to' => 'in',
        'pot_transfer_to' => 'out',
        'salary_received_from' => 'in',
    ];

    public function __construct(private SpendingScope $spending) {}

    /**
     * @return array{layout: string, details: array<string, mixed>|null}
     */
    public function for(Event $event, int|string $userId): array
    {
        if ($event->action === 'had_event') {
            $flight = CalendarFlight::from($event);

            return $flight !== null
                ? ['layout' => 'journey', 'details' => $flight]
                : ['layout' => 'calendar', 'details' => $this->calendar($event)];
        }

        if ($event->domain === 'money' && $this->isTransfer($event)) {
            return ['layout' => 'transfer', 'details' => $this->transfer($event)];
        }

        if ($event->domain === 'money' && in_array($event->action, self::PAYMENT_ACTIONS, true)) {
            return ['layout' => 'payment', 'details' => $this->payment($event, (string) $userId)];
        }

        if ($this->isReading($event)) {
            return ['layout' => 'reading', 'details' => $this->reading($event)];
        }

        return ['layout' => 'metric', 'details' => null];
    }

    /**
     * Whether an event without a value can still have a card: only articles,
     * since anything else valueless has nothing the prose doesn't already say.
     */
    public function isReading(Event $event): bool
    {
        return $event->domain === 'knowledge'
            && ($event->service === 'newsletter' || $event->action === 'bookmarked' || $event->service === 'fetch');
    }

    /**
     * @return array<string, mixed>
     */
    private function calendar(Event $event): array
    {
        $minutes = is_numeric($event->formatted_value) && $event->value_unit === 'minutes'
            ? (int) round((float) $event->formatted_value)
            : null;

        return [
            'title' => trim((string) ($event->target?->title ?? '')) ?: null,
            'starts_at' => $event->time?->toIso8601String(),
            'ends_at' => $event->time && $minutes ? $event->time->copy()->addMinutes($minutes)->toIso8601String() : null,
            'duration_minutes' => $minutes,
            'all_day' => (bool) data_get($event->event_metadata, 'all_day', false),
        ];
    }

    /**
     * Money moving between accounts or people. An open-banking line into an
     * account counts as a refund only on a credit card (as SpendingScope
     * has it); into any other account it is a transfer, salary included.
     */
    private function isTransfer(Event $event): bool
    {
        if (array_key_exists($event->action, self::TRANSFER_DIRECTIONS)) {
            return true;
        }

        return $event->action === SpendingScope::BANK_IN
            && ($event->actor?->metadata['account_type'] ?? null) !== 'credit_card';
    }

    /**
     * Which way the money went, the other party (a pot, a person, or the
     * reference the bank gave) and the reader's account. A pot withdrawal
     * names the pot as its actor and the account as its target, so the two
     * swap there.
     *
     * @return array{direction: string, counterparty: string|null, account: string|null, pending: bool}
     */
    private function transfer(Event $event): array
    {
        $meta = $event->event_metadata ?? [];
        $fromPot = $event->action === 'pot_withdrawal_to';
        $account = $fromPot ? $event->target : $event->actor;
        $other = $fromPot ? $event->actor : $event->target;

        return [
            'direction' => self::TRANSFER_DIRECTIONS[$event->action] ?? 'in',
            'counterparty' => MerchantName::display((string) ($other?->title ?: ($meta['description'] ?? ''))) ?: null,
            'account' => $account?->title,
            'pending' => $this->isPending($event),
        ];
    }

    private function isPending(Event $event): bool
    {
        $meta = $event->event_metadata ?? [];

        return $event->service === 'gocardless'
            ? ($meta['transaction_status'] ?? null) === 'pending'
            : (bool) ($meta['amount_is_pending'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(Event $event, string $userId): array
    {
        $meta = $event->event_metadata ?? [];
        $refund = in_array($event->action, [SpendingScope::MONZO_REFUND, SpendingScope::BANK_IN], true);

        return [
            'merchant' => MerchantName::display((string) ($event->target?->title ?: ($meta['description'] ?? ''))) ?: null,
            'account' => $event->actor?->title,
            'pending' => $this->isPending($event),
            'refund' => $refund,
            'week' => $refund ? null : $this->week($event, $userId),
        ];
    }

    /**
     * This payment among the week's spending up to and including its day:
     * each payment's amount in time order (when there are more than ten,
     * this one and the nine largest others), which one this is, and a
     * caption when it stands out.
     *
     * @return array{amounts: array<int, int>, index: int, caption: string|null}|null
     */
    private function week(Event $event, string $userId): ?array
    {
        if ($event->time === null) {
            return null;
        }

        $entries = $this->spending->entries(
            $userId,
            $event->time->copy()->subDays(6)->startOfDay(),
            $event->time->copy()->endOfDay(),
        )->filter(fn (array $entry): bool => $entry['amount'] > 0);

        if (! $entries->contains(fn (array $entry): bool => $entry['id'] === (string) $event->id)) {
            return null;
        }

        $isThis = fn (array $entry): bool => $entry['id'] === (string) $event->id;
        $ranked = $entries->sortByDesc('amount')->values();
        $rank = $ranked->search($isThis);

        $shown = $ranked->reject($isThis)->take(self::WEEK_BARS - 1)
            ->push($ranked->first($isThis))
            ->sortBy(fn (array $entry): int => $entry['time']->getTimestamp())
            ->values();

        return [
            'amounts' => $shown->pluck('amount')->map(fn (mixed $amount): int => (int) $amount)->all(),
            'index' => (int) $shown->search($isThis),
            'caption' => match ($rank) {
                0 => $entries->count() > 1 ? 'Largest this week' : null,
                1 => '2nd largest this week',
                2 => '3rd largest this week',
                default => null,
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reading(Event $event): array
    {
        $blocks = $event->relationLoaded('blocks') ? $event->blocks : $event->blocks()->get();
        $takeaways = $blocks->first(fn ($block): bool => str_ends_with((string) $block->block_type, '_key_takeaways'));
        $content = $event->displayTargetContent();
        $words = $content ? str_word_count(strip_tags(Str::markdown($content))) : 0;

        return [
            'publication' => $this->publication($event),
            'headline' => $this->headline($event),
            'url' => $event->displayTargetUrl(),
            'read_minutes' => $words > 0 ? max(1, (int) round($words / self::READING_SPEED)) : null,
            'takeaways' => array_slice(KeyTakeaways::normalise($takeaways?->getContent()) ?? [], 0, self::MAX_TAKEAWAYS),
        ];
    }

    /**
     * A newsletter targets its publication, so its own headline is the email
     * subject; anything else is titled by its target.
     */
    private function headline(Event $event): ?string
    {
        if ($event->service === 'newsletter') {
            $subject = trim((string) data_get($event->event_metadata, 'email_subject', ''));

            if ($subject !== '') {
                return $subject;
            }
        }

        return $event->displayTargetTitle() ?: null;
    }

    /**
     * The masthead for a newsletter, otherwise the page's host without "www.".
     */
    private function publication(Event $event): ?string
    {
        if ($event->service === 'newsletter') {
            $publication = $event->target?->title ?? data_get($event->event_metadata, 'email_from_name');

            return filled($publication) ? (string) $publication : null;
        }

        $host = parse_url((string) $event->displayTargetUrl(), PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', $host) : null;
    }
}
