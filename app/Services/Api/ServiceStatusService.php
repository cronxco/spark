<?php

namespace App\Services\Api;

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;

class ServiceStatusService
{
    public function forDay(User $user, Carbon $date): array
    {
        $timezone = $user->getTimezone();
        $localDate = Carbon::parse($date->toDateString(), $timezone)->startOfDay();
        $start = $localDate->copy()->utc();
        $end = $localDate->copy()->endOfDay()->utc();
        $events = Event::query()
            ->whereIn('integration_id', $user->integrations()->pluck('id'))
            ->whereBetween('time', [$start, $end])
            ->get();

        return [
            'date' => $localDate->toDateString(),
            'timezone' => $timezone,
            'total_events' => $events->count(),
            'services' => $events->groupBy('service')->map(function ($serviceEvents, string $service) use ($localDate): array {
                $lastEvent = $serviceEvents->sortByDesc('time')->first();
                $lastUpdated = $serviceEvents->sortByDesc('updated_at')->first();
                $status = [
                    'event_count' => $serviceEvents->count(),
                    'last_event_time' => $lastEvent->time->toIso8601String(),
                    'last_updated_at' => $lastUpdated->updated_at->toIso8601String(),
                    'freshness_basis' => 'updated_at',
                    'actions' => $serviceEvents->pluck('action')->unique()->sort()->values()->all(),
                ];
                if ($service === 'apple_health') {
                    $referenceTime = $localDate->isToday() ? now() : $localDate->copy()->endOfDay();
                    $hours = $lastUpdated->updated_at->lessThan($referenceTime)
                        ? $lastUpdated->updated_at->diffInHours($referenceTime)
                        : 0;
                    $status['coverage'] = $hours > 2 ? 'partial' : 'complete';
                    if ($hours > 2) {
                        $status['coverage_note'] = "Last updated {$hours}h ago — data may be incomplete.";
                    }
                }

                return $status;
            })->all(),
        ];
    }
}
