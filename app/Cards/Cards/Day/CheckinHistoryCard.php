<?php

namespace App\Cards\Cards\Day;

use App\Cards\Base\BaseCard;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;

class CheckinHistoryCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Always eligible - shows history
        return true;
    }

    public function getPriority(): int
    {
        return 10; // Low priority - show near end
    }

    public function getTitle(): string
    {
        return 'Check-in History';
    }

    public function getIcon(): string
    {
        return 'fas.chart-simple';
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.day.checkin-history';
    }

    public function getData(User $user, string $date): array
    {
        // Get last 30 days of check-in events
        $endDate = Carbon::parse($date)->startOfDay();
        $startDate = $endDate->copy()->subDays(29);

        // A check-in belongs to the day it was submitted for, which is in its
        // source_id and metadata, not to the UTC date it was recorded at. This
        // matches the mobile check-in history endpoint.
        $sourceIds = [];
        for ($d = $startDate->copy(); $d <= $endDate; $d->addDay()) {
            $sourceIds[] = 'daily_checkin_morning_' . $d->toDateString();
            $sourceIds[] = 'daily_checkin_afternoon_' . $d->toDateString();
        }

        $events = Event::whereHas('integration', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->where('service', 'daily_checkin');
        })
            ->whereIn('source_id', $sourceIds)
            ->whereIn('action', ['had_morning_checkin', 'had_afternoon_checkin'])
            ->orderBy('time', 'asc')
            ->get();

        // Group by date and period
        $history = [];
        for ($d = $startDate->copy(); $d <= $endDate; $d->addDay()) {
            $dateKey = $d->format('Y-m-d');
            $history[$dateKey] = [
                'date' => $dateKey,
                'morning' => null,
                'afternoon' => null,
            ];
        }

        foreach ($events as $event) {
            $metadata = $event->event_metadata ?? [];
            $dateKey = $metadata['date'] ?? $event->time->format('Y-m-d');
            if (isset($history[$dateKey])) {
                $period = $metadata['period'] ?? ($event->action === 'had_morning_checkin' ? 'morning' : 'afternoon');

                $history[$dateKey][$period] = [
                    'physical' => $metadata['physical_energy'] ?? null,
                    'mental' => $metadata['mental_energy'] ?? null,
                    'total' => $event->value,
                ];
            }
        }

        return [
            'history' => array_values($history),
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
        ];
    }
}
