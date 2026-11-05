<?php

namespace App\Cards\Cards\Day;

use App\Cards\Base\BaseCard;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\User;
use Carbon\Carbon;

class AfternoonCheckinCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Show in the afternoon/evening (4pm onwards) for today if not yet completed
        // $now is already in user's timezone (from CardRegistry)
        $today = user_today($user);
        $isToday = Carbon::parse($date)->isSameDay($today);
        $isAfternoon = $now->hour >= 12;

        if (! $isToday || ! $isAfternoon) {
            return false;
        }

        // Check if afternoon check-in is already completed
        $plugin = new DailyCheckinPlugin;
        $checkins = $plugin->getCheckinsForDate($user->id, $date);

        return ! $checkins['afternoon'];
    }

    public function getPriority(): int
    {
        return 90; // High priority, after intro
    }

    public function getTitle(): string
    {
        return 'Afternoon Check-in';
    }

    public function getIcon(): string
    {
        return 'o-clipboard-document-check';
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.day.afternoon-checkin';
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
