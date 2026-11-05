<?php

namespace App\Services\Mobile;

use App\Models\Event;

/**
 * Reads the GPS route a workout event carries in `event_metadata.route_points`
 * (written by the Apple Health plugin) and shapes it for the mobile map.
 */
class EventRoute
{
    /** Most points the mobile route payload carries; longer routes are thinned evenly. */
    public const MAX_POINTS = 1000;

    public function hasRoute(Event $event): bool
    {
        return $this->validPoints($event) !== [];
    }

    /**
     * The route for the map, or null when the event has no usable points.
     *
     * @return array{points: list<array{lat: float, lng: float}>, total_points: int, distance: float|null, distance_unit: string|null, duration_seconds: float|null}|null
     */
    public function forEvent(Event $event): ?array
    {
        $points = $this->validPoints($event);

        if ($points === []) {
            return null;
        }

        $metadata = $event->event_metadata ?? [];

        return [
            'points' => $this->thin($points),
            'total_points' => count($points),
            'distance' => is_numeric($metadata['distance'] ?? null) ? (float) $metadata['distance'] : null,
            'distance_unit' => is_string($metadata['distance_unit'] ?? null) ? $metadata['distance_unit'] : null,
            'duration_seconds' => is_numeric($metadata['duration_seconds'] ?? null) ? (float) $metadata['duration_seconds'] : null,
        ];
    }

    /**
     * Route points with real coordinates, in recorded order.
     *
     * @return list<array{lat: float, lng: float}>
     */
    protected function validPoints(Event $event): array
    {
        $raw = $event->event_metadata['route_points'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $points = [];

        foreach ($raw as $point) {
            $lat = is_array($point) ? ($point['lat'] ?? null) : null;
            $lng = is_array($point) ? ($point['lng'] ?? null) : null;

            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $lat = (float) $lat;
            $lng = (float) $lng;

            if (abs($lat) > 90 || abs($lng) > 180) {
                continue;
            }

            $points[] = ['lat' => $lat, 'lng' => $lng];
        }

        return $points;
    }

    /**
     * Keep at most MAX_POINTS, evenly spaced, always keeping the first and last point.
     *
     * @param  list<array{lat: float, lng: float}>  $points
     * @return list<array{lat: float, lng: float}>
     */
    protected function thin(array $points): array
    {
        $count = count($points);

        if ($count <= self::MAX_POINTS) {
            return $points;
        }

        $step = ($count - 1) / (self::MAX_POINTS - 1);
        $thinned = [];

        for ($index = 0; $index < self::MAX_POINTS; $index++) {
            $thinned[] = $points[(int) round($index * $step)];
        }

        return $thinned;
    }
}
