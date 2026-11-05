<?php

namespace App\Mcp\Concerns;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Services\EffectiveTimezoneResolver;

/**
 * Serialises events for MCP tools with their local wall-clock time alongside
 * the canonical UTC `time`, so an agent never has to (or forgets to) convert.
 *
 * Each event is rendered in the timezone that was in effect at its own instant
 * (the acknowledged time-travel zone, else the profile zone), so a historical
 * event reads in the zone the user was actually in.
 */
trait PresentsEventTimes
{
    /**
     * @return array<string, mixed>
     */
    protected function presentEvent(Event $event, ?User $user): array
    {
        $data = (new EventResource($event))->resolve(request());

        if ($event->time === null || $user === null) {
            return $data;
        }

        $timezone = app(EffectiveTimezoneResolver::class)->timezoneForAt($user, $event->time);
        $localised = [];

        foreach ($data as $key => $value) {
            $localised[$key] = $value;

            if ($key === 'time') {
                $localised['local_time'] = $event->time->copy()->setTimezone($timezone)->toIso8601String();
                $localised['timezone'] = $timezone;
            }
        }

        return $localised;
    }

    /**
     * @param  iterable<Event>  $events
     * @return array<int, array<string, mixed>>
     */
    protected function presentEvents(iterable $events, ?User $user): array
    {
        $presented = [];

        foreach ($events as $event) {
            $presented[] = $this->presentEvent($event, $user);
        }

        return $presented;
    }
}
