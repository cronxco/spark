<?php

namespace App\Cards\Cards\Day;

use App\Cards\Base\BaseCard;
use App\Jobs\OAuth\Oura\OuraReadinessPull;
use App\Jobs\OAuth\Oura\OuraSleepPull;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;

class MorningIntroCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Only show in the morning (6am - 12pm) for today
        // $now is already in user's timezone (from CardRegistry)
        $today = user_today($user);
        $isToday = Carbon::parse($date)->isSameDay($today);
        $isMorning = $now->hour >= 6 && $now->hour < 12;

        return $isToday && $isMorning;
    }

    public function getPriority(): int
    {
        return 100; // Highest priority - show first
    }

    public function getTitle(): string
    {
        return 'Good Morning';
    }

    public function getIcon(): string
    {
        return 'o-sun';
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.day.morning-intro';
    }

    public function shouldTriggerSync(): bool
    {
        return true;
    }

    public function getSyncJobs(User $user, string $date): array
    {
        $jobs = [];

        // Trigger Oura sleep and readiness syncs if integration exists
        $ouraIntegrations = Integration::where('user_id', $user->id)
            ->where('service', 'oura')
            ->get();

        foreach ($ouraIntegrations as $integration) {
            if ($integration->instance_type === 'sleep') {
                $jobs[OuraSleepPull::class] = [$integration];
            }
            if ($integration->instance_type === 'readiness') {
                $jobs[OuraReadinessPull::class] = [$integration];
            }
        }

        return $jobs;
    }

    public function getData(User $user, string $date): array
    {
        $now = user_now($user);

        return [
            'greeting' => $this->getGreeting($now),
            'userName' => $user->name,
        ];
    }

    protected function getGreeting(Carbon $now): string
    {
        $hour = $now->hour;

        if ($hour < 8) {
            return 'Rise and shine';
        }

        if ($hour < 10) {
            return 'Good morning';
        }

        return 'Morning';
    }
}
