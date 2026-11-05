<?php

namespace App\Cards\Cards\Day;

use App\Cards\Base\BaseCard;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\User;
use Carbon\Carbon;

class MorningCheckinCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Show in the morning (6am - 12pm) for today if not yet completed
        // $now is already in user's timezone (from CardRegistry)
        $today = user_today($user);
        $isToday = Carbon::parse($date)->isSameDay($today);
        $isMorning = $now->hour >= 4 && $now->hour < 12;

        if (! $isToday || ! $isMorning) {
            return false;
        }

        // Check if morning check-in is already completed
        $plugin = new DailyCheckinPlugin;
        $checkins = $plugin->getCheckinsForDate($user->id, $date);

        return ! $checkins['morning'];
    }

    public function getPriority(): int
    {
        return 90; // High priority, after intro
    }

    public function getTitle(): string
    {
        return 'Morning Check-in';
    }

    public function getIcon(): string
    {
        return 'o-clipboard-document-check';
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.day.morning-checkin';
    }

    public function requiresInteraction(): bool
    {
        return true; // Requires user to submit check-in
    }

    public function getData(User $user, string $date): array
    {
        return [
            'date' => $date,
        ];
    }
}
