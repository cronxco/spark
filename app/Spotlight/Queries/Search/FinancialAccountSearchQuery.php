<?php

namespace App\Spotlight\Queries\Search;

use App\Models\Event;
use App\Models\EventObject;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class FinancialAccountSearchQuery
{
    /**
     * Create Spotlight query for searching financial accounts.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            if (blank($query) || strlen($query) < 2) {
                return collect();
            }

            // Search for financial account objects
            return EventObject::query()
                ->where('concept', 'account')
                ->whereIn('type', [
                    'manual_account',
                    'monzo_account',
                    'monzo_pot',
                    'bank_account',
                ])
                ->where('title', 'ilike', "%{$query}%")
                ->limit(5)
                ->get()
                ->map(function (EventObject $account) {
                    // Get account metadata
                    $provider = $account->metadata['provider'] ?? null;
                    $accountType = $account->metadata['account_type'] ?? $account->type;

                    $subtitle = $provider ? ucfirst($provider) : ucfirst(str_replace('_', ' ', $accountType));

                    // Try to get latest balance from events
                    $latestBalance = Event::where('actor_id', $account->id)
                        ->whereIn('service', ['manual_account', 'monzo', 'gocardless'])
                        ->where('action', 'had_balance')
                        ->latest('time')
                        ->first();

                    if ($latestBalance) {
                        $balanceValue = $latestBalance->value / ($latestBalance->value_multiplier ?: 1);
                        $currency = $latestBalance->value_unit ?? 'GBP';
                        $currencySymbol = $currency === 'GBP' ? '£' : $currency;
                        $subtitle .= ' • ' . $currencySymbol . number_format($balanceValue, 2);
                        $subtitle .= ' • Updated ' . $latestBalance->time->diffForHumans();

                        // Boost priority for recently updated accounts
                        $priority = $latestBalance->time->isAfter(now()->subWeek()) ? 1 : 2;
                    } else {
                        $priority = 2;
                    }

                    return SpotlightResult::make()
                        ->setTitle($account->title)
                        ->setSubtitle($subtitle)
                        ->setTypeahead('Account: ' . $account->title)
                        ->setIcon('currency-pound')
                        ->setGroup('accounts')
                        ->setPriority($priority)
                        ->setAction('jump_to', ['path' => route('money.show', $account)]);
                });
        });
    }
}
