<?php

namespace App\Services\Api;

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;

class ServiceStatusService
{
    public function forDay(User $user, Carbon $date): array
    {
        $events = Event::query()
            ->whereIn('integration_id', $user->integrations()->pluck('id'))
            ->whereBetween('time', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->get();

        return [
            'date' => $date->toDateString(),
            'total_events' => $events->count(),
            'services' => $events->groupBy('service')->map(function ($serviceEvents, string $service) use ($date): array {
                $lastEvent = $serviceEvents->sortByDesc('time')->first();
                $status = [
                    'event_count' => $serviceEvents->count(),
                    'last_event_time' => $lastEvent->time->toIso8601String(),
                    'actions' => $serviceEvents->pluck('action')->unique()->sort()->values()->all(),
                ];
                if ($service === 'apple_health') {
                    $referenceTime = $date->isToday() ? now() : $date->copy()->endOfDay();
                    $hours = $lastEvent->time->diffInHours($referenceTime);
                    $status['coverage'] = $hours > 2 ? 'partial' : 'complete';
                    if ($hours > 2) {
                        $status['coverage_note'] = "Last event was {$hours}h ago — data may be incomplete.";
                    }
                }

                return $status;
            })->all(),
        ];
    }
}
