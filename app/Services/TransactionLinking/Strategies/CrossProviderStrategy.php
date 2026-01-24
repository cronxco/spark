<?php

namespace App\Services\TransactionLinking\Strategies;

use App\Models\Event;
use App\Models\EventObject;
use App\Services\TransactionLinking\Contracts\LinkingStrategy;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Strategy for matching payments across different bank providers.
 *
 * Handles: Direct debit from one account matching credit on another (e.g., paying off credit card)
 */
class CrossProviderStrategy implements LinkingStrategy
{
    /**
     * Known credit card provider merchant names to look for.
     */
    private const CREDIT_CARD_PROVIDERS = [
        'American Express' => ['amex', 'american express'],
        'Barclaycard' => ['barclaycard'],
        'HSBC' => ['hsbc'],
        'NatWest' => ['natwest'],
        'Lloyds' => ['lloyds'],
        'Nationwide' => ['nationwide'],
        'Monzo' => ['monzo flex'],
    ];

    /**
     * Maximum days between debit and credit for matching.
     */
    private const MAX_SETTLEMENT_DAYS = 3;

    public function getIdentifier(): string
    {
        return 'cross_provider';
    }

    public function getName(): string
    {
        return 'Cross-Provider Payment';
    }

    public function canProcess(Event $event): bool
    {
        // Only process direct debits or bank transfers that might be credit card payments
        if (! in_array($event->action, ['direct_debit_to', 'bank_transfer_to', 'faster_payment_to'])) {
            return false;
        }

        // Check if target is a known credit card provider
        return $this->isCreditCardPayment($event);
    }

    public function findLinks(Event $event): Collection
    {
        $links = collect();

        // Extract card identification from the event
        $cardId = $this->extractCardIdentification($event);
        if (! $cardId) {
            return $links;
        }

        // Find credit card accounts that match this card ID
        $creditCardAccounts = $this->findMatchingCreditCardAccounts($event, $cardId);
        if ($creditCardAccounts->isEmpty()) {
            return $links;
        }

        // Search for matching credits on those accounts
        $eventDate = Carbon::parse($event->time);
        $startDate = $eventDate->copy()->subDay();
        $endDate = $eventDate->copy()->addDays(self::MAX_SETTLEMENT_DAYS);

        foreach ($creditCardAccounts as $account) {
            $matchingCredits = $this->findMatchingCredits($event, $account, $startDate, $endDate);

            foreach ($matchingCredits as $credit) {
                $confidence = $this->calculateConfidence($event, $credit, $cardId);

                $links->push([
                    'target_event' => $credit,
                    'relationship_type' => 'payment_for',
                    'confidence' => $confidence,
                    'matching_criteria' => [
                        'type' => 'cross_provider_payment',
                        'debit_amount' => $event->value,
                        'credit_amount' => $credit->value,
                        'card_identification' => $cardId,
                        'account_id' => $account->id,
                        'days_apart' => abs($eventDate->diffInDays(Carbon::parse($credit->time))),
                    ],
                    'value' => $event->value,
                    'value_multiplier' => $event->value_multiplier,
                    'value_unit' => $event->value_unit,
                ]);
            }
        }

        return $links;
    }

    /**
     * Check if this event appears to be a credit card payment.
     */
    private function isCreditCardPayment(Event $event): bool
    {
        // Check merchant name against known providers
        $targetName = strtolower($event->target?->title ?? '');
        $description = strtolower(data_get($event->event_metadata, 'raw.description', ''));

        foreach (self::CREDIT_CARD_PROVIDERS as $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($targetName, $keyword) || str_contains($description, $keyword)) {
                    return true;
                }
            }
        }

        // Also check if category is "transfers" and merchant looks like a bank
        $category = data_get($event->event_metadata, 'category', '');

        return $category === 'transfers' && $this->extractCardIdentification($event) !== null;
    }

    /**
     * Extract card identification (masked PAN) from the event.
     */
    private function extractCardIdentification(Event $event): ?string
    {
        // Check description/notes for masked card number pattern
        $description = data_get($event->event_metadata, 'raw.description', '');
        $notes = data_get($event->event_metadata, 'raw.notes', '');

        // Look for patterns like "************4006" or "***********1002"
        foreach ([$description, $notes] as $text) {
            if (preg_match('/\*+(\d{4})/', $text, $matches)) {
                return $matches[1];  // Return last 4 digits
            }
        }

        return null;
    }

    /**
     * Find credit card accounts that match the given card identification.
     */
    private function findMatchingCreditCardAccounts(Event $event, string $cardId): Collection
    {
        $userId = $event->integration->user_id;

        return EventObject::where('user_id', $userId)
            ->where('concept', 'account')
            ->where(function ($q) {
                $q->where('type', 'bank_account')
                    ->orWhere('type', 'credit_card');
            })
            ->where(function ($q) use ($cardId) {
                // Match on masked PAN in metadata
                $q->whereRaw("metadata->>'account_number' LIKE ?", ['%'.$cardId])
                    ->orWhereRaw("metadata->'raw'->>'maskedPan' LIKE ?", ['%'.$cardId]);
            })
            ->get();
    }

    /**
     * Find matching credit events on the given account.
     */
    private function findMatchingCredits(Event $event, EventObject $account, Carbon $startDate, Carbon $endDate): Collection
    {
        return Event::where('actor_id', $account->id)
            ->where('id', '!=', $event->id)
            ->where('value', $event->value)  // Exact amount match
            ->whereBetween('time', [$startDate, $endDate])
            ->where('value', '>', 0)  // Credits are positive
            ->whereHas('integration', function ($q) use ($event) {
                $q->where('user_id', $event->integration->user_id);
            })
            ->get();
    }

    /**
     * Calculate confidence score for a potential match.
     */
    private function calculateConfidence(Event $debit, Event $credit, string $cardId): float
    {
        $confidence = 0.0;

        // Amount match (exact) = 40%
        if ($debit->value === $credit->value) {
            $confidence += 40.0;
        }

        // Date proximity
        $debitDate = Carbon::parse($debit->time);
        $creditDate = Carbon::parse($credit->time);
        $daysDiff = abs($debitDate->diffInDays($creditDate));

        if ($daysDiff === 0) {
            $confidence += 30.0;  // Same day
        } elseif ($daysDiff === 1) {
            $confidence += 20.0;  // Next day
        } elseif ($daysDiff <= 3) {
            $confidence += 10.0;  // Within 3 days
        }

        // Card ID match in account = 30%
        $accountNumber = $credit->actor?->metadata['account_number'] ?? '';
        $maskedPan = data_get($credit->actor?->metadata, 'raw.maskedPan', '');

        if (str_ends_with($accountNumber, $cardId) || str_ends_with($maskedPan, $cardId)) {
            $confidence += 30.0;
        }

        return min($confidence, 100.0);
    }
}
