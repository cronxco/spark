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
 * - `payment`: a card or bank payment (merchant, account, pending, and where
 *   it sits among the week's spending).
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
     * @return array<string, mixed>
     */
    private function payment(Event $event, string $userId): array
    {
        $meta = $event->event_metadata ?? [];
        $refund = in_array($event->action, [SpendingScope::MONZO_REFUND, SpendingScope::BANK_IN], true);
        $pending = $event->service === 'gocardless'
            ? ($meta['transaction_status'] ?? null) === 'pending'
            : (bool) ($meta['amount_is_pending'] ?? false);

        return [
            'merchant' => MerchantName::display((string) ($event->target?->title ?: ($meta['description'] ?? ''))) ?: null,
            'account' => $event->actor?->title,
            'pending' => $pending,
            'refund' => $refund,
            'week' => $refund ? null : $this->week($event, $userId),
        ];
    }

    /**
     * This payment among the week's spending up to and including its day:
     * each payment's amount in time order (the largest ten when there are
     * more), which one this is, and a caption when it stands out.
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

        $rank = $entries->sortByDesc('amount')->values()
            ->search(fn (array $entry): bool => $entry['id'] === (string) $event->id);

        $shown = $entries->sortByDesc('amount')->take(self::WEEK_BARS)
            ->sortBy(fn (array $entry): int => $entry['time']->getTimestamp())
            ->values();

        return [
            'amounts' => $shown->pluck('amount')->map(fn (mixed $amount): int => (int) $amount)->all(),
            'index' => (int) $shown->search(fn (array $entry): bool => $entry['id'] === (string) $event->id),
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
