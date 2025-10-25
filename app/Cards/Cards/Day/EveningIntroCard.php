<?php

namespace App\Cards\Cards\Day;

use App\Cards\Base\BaseCard;
use App\Models\User;
use Carbon\Carbon;

class EveningIntroCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Only show in the evening (6pm - 11pm) for today
        // $now is already in user's timezone (from CardRegistry)
        $today = user_today($user);
        $isToday = Carbon::parse($date)->isSameDay($today);
        $isEvening = $now->hour >= 18 && $now->hour < 23;

        return $isToday && $isEvening;
    }

    public function getPriority(): int
    {
        return 100; // Highest priority - show first
    }

    public function getTitle(): string
    {
        return 'Good Evening';
    }

    public function getIcon(): string
    {
        return 'o-moon';
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.day.evening-intro';
    }

    public function getData(User $user, string $date): array
    {
        return [
            'userName' => $user->name,
        ];
    }
}
