<?php

namespace App\Livewire\Charts;

use App\Models\Event;
use App\Models\EventObject;
use Livewire\Component;

class BalanceHistoryChart extends Component
{
    public EventObject $account;

    public int $rangeMonths = 6;

    public function mount(): void
    {
        // Set default range based on account age
        $firstBalance = Event::where('actor_id', $this->account->id)
            ->whereIn('service', ['manual_account', 'monzo', 'gocardless'])
            ->where('action', 'had_balance')
            ->orderBy('time')
            ->first();

        if ($firstBalance) {
            $accountAgeMonths = now()->diffInMonths($firstBalance->time);
            if ($accountAgeMonths < 6) {
                $this->rangeMonths = 0; // All time
            }
        }
    }

    public function render()
    {
        $startDate = $this->rangeMonths === 0
            ? null // All time
            : now()->subMonths($this->rangeMonths);

        $isNegativeBalance = ($this->account->metadata['negative_balance'] ?? false) === true;

        $balances = Event::where('actor_id', $this->account->id)
            ->whereIn('service', ['manual_account', 'monzo', 'gocardless'])
            ->where('action', 'had_balance')
            ->when($startDate, fn ($q) => $q->where('time', '>=', $startDate))
            ->orderBy('time')
            ->get(['id', 'time', 'value', 'event_metadata'])
            ->map(function ($event) use ($isNegativeBalance) {
                // Handle different balance storage formats
                $balance = $event->event_metadata['balance'] ?? $event->value;

                // For negative balance accounts, invert the sign for display
                if ($isNegativeBalance && $balance !== null) {
                    $balance = -$balance;
                }

                return [
                    'id' => (string) $event->id,
                    'date' => $event->time->format('Y-m-d'),
                    'datetime' => $event->time->format('d/m/Y H:i'),
                    'balance' => $balance,
                ];
            })
            ->values();

        // Get currency symbol
        $currency = $this->account->metadata['currency'] ?? 'GBP';
        $currencySymbol = $currency === 'GBP' ? '£' : '$';

        return view('livewire.charts.balance-history-chart', [
            'chartData' => $balances,
            'currencySymbol' => $currencySymbol,
        ]);
    }
}
