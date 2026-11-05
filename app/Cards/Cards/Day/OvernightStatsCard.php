<?php

namespace App\Cards\Cards\Day;

use App\Cards\Base\BaseCard;
use App\Models\Block;
use App\Models\User;
use Carbon\Carbon;

class OvernightStatsCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Show in the morning (6am - 12pm) for today if Oura data exists
        // $now is already in user's timezone (from CardRegistry)
        $today = user_today($user);
        $isToday = Carbon::parse($date)->isSameDay($today);
        $isMorning = $now->hour >= 6 && $now->hour < 12;

        if (! $isToday || ! $isMorning) {
            return false;
        }

        // Check if we have Oura sleep or readiness data for last night
        $hasOuraData = Block::whereHas('event.integration', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->where('service', 'oura');
        })
            ->whereDate('time', $date)
            ->whereIn('block_type', ['sleep_score', 'readiness_score', 'total_sleep'])
            ->exists();

        return $hasOuraData;
    }

    public function getPriority(): int
    {
        return 85; // Show after check-in
    }

    public function getTitle(): string
    {
        return 'Last Night';
    }

    public function getIcon(): string
    {
        return 'fas.moon';
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.day.overnight-stats';
    }

    public function getData(User $user, string $date): array
    {
        // Get Oura blocks for the date
        $blocks = Block::whereHas('event.integration', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->where('service', 'oura');
        })
            ->whereDate('time', $date)
            ->whereIn('block_type', ['sleep_score', 'readiness_score', 'total_sleep', 'rem_sleep', 'deep_sleep', 'light_sleep'])
            ->get()
            ->keyBy('block_type');

        return [
            'sleepScore' => $blocks->get('sleep_score')?->value,
            'readinessScore' => $blocks->get('readiness_score')?->value,
            'totalSleep' => $blocks->get('total_sleep')?->value,
            'remSleep' => $blocks->get('rem_sleep')?->value,
            'deepSleep' => $blocks->get('deep_sleep')?->value,
            'lightSleep' => $blocks->get('light_sleep')?->value,
        ];
    }
}
