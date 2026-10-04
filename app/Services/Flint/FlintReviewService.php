<?php

namespace App\Services\Flint;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Models\Event;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Receipt\ReceiptMatchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The Flint "Review" queue (decisions E-1 and EX-D4): automated decisions and
 * suggestions a person should be able to check, from one place on web and iOS.
 *
 * Four kinds of item, all read from existing rows:
 *  - `receipt_suggestion`: a receipt Spark could not match with confidence,
 *    with its candidate transactions;
 *  - `receipt_auto_match`: a receipt Spark linked to a transaction by itself;
 *  - `link_suggestion`: a pending transaction-to-transaction link;
 *  - `auto_link`: a transaction link Spark approved by itself.
 *
 * Automatic decisions stay in the queue until someone keeps or undoes them,
 * for AUTO_DECISION_DAYS. Keeping one records `reviewed_at` on the
 * relationship's metadata. Confidence is always reported on a 0–1 scale (E-6).
 */
class FlintReviewService
{
    public const KINDS = ['receipt_suggestion', 'receipt_auto_match', 'link_suggestion', 'auto_link'];

    public const AUTO_DECISION_DAYS = 30;

    private const LIMIT = 50;

    /** @return array<int, array<string, mixed>> */
    public function items(User $user): array
    {
        return collect()
            ->merge($this->receiptSuggestions($user))
            ->merge($this->receiptAutoMatches($user))
            ->merge($this->linkSuggestions($user))
            ->merge($this->autoLinks($user))
            ->sort(function (array $left, array $right): int {
                $leftNeedsDecision = in_array($left['kind'], ['receipt_suggestion', 'link_suggestion'], true);
                $rightNeedsDecision = in_array($right['kind'], ['receipt_suggestion', 'link_suggestion'], true);

                return ($rightNeedsDecision <=> $leftNeedsDecision)
                    ?: strcmp($right['created_at'] ?? '', $left['created_at'] ?? '');
            })
            ->values()
            ->all();
    }

    public function count(User $user): int
    {
        return count($this->items($user));
    }

    /**
     * Applies a person's decision to one item.
     *
     * @param  array{transaction_id?: string|null}  $input
     */
    public function act(User $user, string $kind, string $id, string $action, array $input = []): void
    {
        match ($kind) {
            'receipt_suggestion' => $this->actOnReceiptSuggestion($user, $id, $action, $input['transaction_id'] ?? null),
            'receipt_auto_match', 'auto_link' => $this->actOnAutoDecision($user, $kind, $id, $action),
            'link_suggestion' => $this->actOnLinkSuggestion($user, $id, $action),
            default => throw new InvalidArgumentException('Unknown review item.'),
        };
    }

    /** @return Collection<int, array<string, mixed>> */
    private function receiptSuggestions(User $user): Collection
    {
        return $this->receipts($user)
            ->where('event_metadata->receipt_matching->status', 'suggestions')
            ->whereNotIn('id', ReceiptMatchState::links()->select('from_id'))
            ->latest('time')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (Event $receipt) use ($user): array {
                $candidates = ReceiptMatchState::candidates($receipt);
                $candidateIds = collect($candidates)->pluck('transaction_id')->filter()->all();
                $owned = Event::forUser($user->id)->whereIn('id', $candidateIds)->with('target')->get()->keyBy('id');

                return [
                    'id' => (string) $receipt->id,
                    'kind' => 'receipt_suggestion',
                    'title' => $receipt->target?->title ?? 'Receipt',
                    'summary' => 'Spark found possible transactions for this receipt but was not sure enough to link one.',
                    'confidence' => isset($candidates[0]['confidence']) ? (float) $candidates[0]['confidence'] : null,
                    'created_at' => $receipt->time?->toIso8601String(),
                    'subject' => $this->eventSummary($receipt),
                    'candidates' => collect($candidates)
                        ->filter(fn (array $candidate) => $owned->has($candidate['transaction_id'] ?? null))
                        ->map(fn (array $candidate) => [
                            ...$this->eventSummary($owned[$candidate['transaction_id']]),
                            'confidence' => (float) ($candidate['confidence'] ?? 0),
                        ])
                        ->values()
                        ->all(),
                    'actions' => ['confirm', 'dismiss'],
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function receiptAutoMatches(User $user): Collection
    {
        return $this->unreviewedAutoDecisions($user)
            ->where('type', 'receipt_for')
            ->where('metadata->match_method', 'automatic')
            ->get()
            ->map(fn (Relationship $link) => $this->linkItem($user, $link, 'receipt_auto_match',
                'Spark linked this receipt to a transaction by itself.', (float) ($link->metadata['match_confidence'] ?? 0)))
            ->filter();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function linkSuggestions(User $user): Collection
    {
        return Relationship::query()
            ->where('user_id', $user->id)
            ->where('from_type', Event::class)
            ->where('to_type', Event::class)
            ->pending()
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Relationship $link) => $this->linkItem($user, $link, 'link_suggestion',
                'Spark thinks these transactions are connected but was not sure enough to link them.', $this->percent($link)))
            ->filter();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function autoLinks(User $user): Collection
    {
        return $this->unreviewedAutoDecisions($user)
            ->whereRaw("(metadata->>'auto_linked')::boolean = true")
            ->get()
            ->map(fn (Relationship $link) => $this->linkItem($user, $link, 'auto_link',
                'Spark linked these transactions by itself.', $this->percent($link)))
            ->filter();
    }

    /** @return Builder<Relationship> */
    private function unreviewedAutoDecisions(User $user): Builder
    {
        return Relationship::query()
            ->where('user_id', $user->id)
            ->where('from_type', Event::class)
            ->where('to_type', Event::class)
            ->confirmed()
            ->whereNull('metadata->reviewed_at')
            ->where('created_at', '>=', now()->subDays(self::AUTO_DECISION_DAYS))
            ->latest()
            ->limit(self::LIMIT);
    }

    /** @return array<string, mixed>|null */
    private function linkItem(User $user, Relationship $link, string $kind, string $summary, ?float $confidence): ?array
    {
        $events = Event::forUser($user->id)->whereIn('id', [$link->from_id, $link->to_id])->with('target')->get()->keyBy('id');
        if (! $events->has($link->from_id) || ! $events->has($link->to_id)) {
            return null;
        }

        return [
            'id' => (string) $link->id,
            'kind' => $kind,
            'title' => $events[$link->from_id]->target?->title ?? 'Transaction',
            'summary' => $summary,
            'confidence' => $confidence,
            'created_at' => $link->created_at?->toIso8601String(),
            'relationship_type' => $link->type,
            'subject' => $this->eventSummary($events[$link->from_id]),
            'linked' => $this->eventSummary($events[$link->to_id]),
            'actions' => $kind === 'link_suggestion' ? ['confirm', 'dismiss'] : ['undo'],
        ];
    }

    private function actOnReceiptSuggestion(User $user, string $id, string $action, ?string $transactionId): void
    {
        $receipt = $this->receipts($user)
            ->where('event_metadata->receipt_matching->status', 'suggestions')
            ->whereNotIn('id', ReceiptMatchState::links()->select('from_id'))
            ->findOrFail($id);

        if ($action === 'confirm') {
            $candidate = collect(ReceiptMatchState::candidates($receipt))->firstWhere('transaction_id', $transactionId);
            if ($candidate === null) {
                throw new InvalidArgumentException('Choose one of the suggested transactions.');
            }
            $transaction = Event::forUser($user->id)->findOrFail($transactionId);
            app(ReceiptTransactionMatcher::class)->createReceiptRelationship($receipt, $transaction, (float) $candidate['confidence'], 'manual');
        } elseif ($action === 'dismiss') {
            ReceiptMatchState::update($receipt, [
                'status' => 'dismissed',
                'candidates' => [],
                'dismissed_at' => now()->toIso8601String(),
            ]);
        } else {
            throw new InvalidArgumentException('A receipt suggestion can be confirmed or dismissed.');
        }
    }

    private function actOnAutoDecision(User $user, string $kind, string $id, string $action): void
    {
        $link = $this->unreviewedAutoDecisions($user)
            ->when($kind === 'receipt_auto_match', fn (Builder $query) => $query
                ->where('type', 'receipt_for')
                ->where('metadata->match_method', 'automatic'))
            ->when($kind === 'auto_link', fn (Builder $query) => $query
                ->whereRaw("(metadata->>'auto_linked')::boolean = true"))
            ->findOrFail($id);

        if ($action === 'keep') {
            $link->update(['metadata' => [...($link->metadata ?? []), 'reviewed_at' => now()->toIso8601String()]]);

            return;
        }
        if ($action !== 'undo') {
            throw new InvalidArgumentException('An automatic decision can be kept or undone.');
        }

        if ($kind === 'receipt_auto_match') {
            $receipt = Event::forUser($user->id)->find($link->from_id);
            if ($receipt) {
                ReceiptMatchState::clear($receipt);
            }
        }

        $link->reject();
    }

    private function actOnLinkSuggestion(User $user, string $id, string $action): void
    {
        $link = Relationship::query()
            ->where('user_id', $user->id)
            ->where('from_type', Event::class)
            ->where('to_type', Event::class)
            ->pending()
            ->findOrFail($id);

        if ($action === 'confirm') {
            // A person approved this link. It must not reappear as an automatic
            // decision merely because the detector originally set auto_linked.
            $link->update(['metadata' => [...($link->metadata ?? []), 'reviewed_at' => now()->toIso8601String()]]);
            $link->approve();

            return;
        }
        if ($action === 'dismiss') {
            $link->reject();

            return;
        }

        throw new InvalidArgumentException('A link suggestion can be confirmed or dismissed.');
    }

    /** @return Builder<Event> */
    private function receipts(User $user): Builder
    {
        return Event::forUser($user->id)
            ->where('service', 'receipt')
            ->where('action', 'had_receipt_from')
            ->with('target');
    }

    private function percent(Relationship $link): ?float
    {
        $confidence = $link->getConfidence();

        return $confidence === null ? null : round($confidence / 100, 2);
    }

    /** @return array{id: string, title: string|null, amount: int|float|null, unit: string|null, time: string|null, service: string} */
    private function eventSummary(Event $event): array
    {
        return [
            'id' => (string) $event->id,
            'title' => $event->target?->title,
            'amount' => $event->formatted_value,
            'unit' => $event->value_unit,
            'time' => $event->time?->toIso8601String(),
            'service' => $event->service,
        ];
    }
}
