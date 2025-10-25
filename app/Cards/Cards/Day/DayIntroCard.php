<?php

namespace App\Cards\Cards\Day;

use App\Cards\Base\BaseCard;
use App\Jobs\OAuth\Oura\OuraActivityPull;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;

class DayIntroCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Only show during the day (12pm - 6pm) for today
        $isToday = Carbon::parse($date)->isToday();
        $isDay = $now->hour >= 12 && $now->hour < 18;

        return $isToday && $isDay;
    }

    public function getPriority(): int
    {
        return 100; // Highest priority - show first
    }

    public function getTitle(): string
    {
        return 'Good Afternoon';
    }

    public function getIcon(): string
    {
        return 'o-sun';
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.day.day-intro';
    }

    public function shouldTriggerSync(): bool
    {
        return true;
    }

    public function getSyncJobs(User $user, string $date): array
    {
        $jobs = [];

        // Trigger Oura activity sync if integration exists
        $ouraIntegrations = Integration::where('user_id', $user->id)
            ->where('service', 'oura')
            ->get();

        foreach ($ouraIntegrations as $integration) {
            if ($integration->instance_type === 'activity') {
                $jobs[OuraActivityPull::class] = [$integration];
            }
        }

        return $jobs;
    }

    public function getData(User $user, string $date): array
    {
        return [
            'userName' => $user->name,
        ];
    }
}
