<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapController extends Controller
{
    public const CLUSTER_THRESHOLD = 500;

    /**
     * GET /api/v1/mobile/map/data?bbox=swLat,swLng,neLat,neLng
     *
     * Returns geo-located events + place objects within the bounding box. When
     * the total exceeds CLUSTER_THRESHOLD the server collapses into coarse
     * clusters keyed by a rounded lat/lng grid — the iOS client decides how to
     * render based on which array is populated.
     */
    public function data(Request $request): JsonResponse
    {
        $bbox = $this->parseBbox((string) $request->query('bbox', ''));

        if ($bbox === null) {
            return response()->json([
                'message' => 'bbox must be four comma-separated floats: swLat,swLng,neLat,neLng.',
            ], 422);
        }

        [$swLat, $swLng, $neLat, $neLng] = $bbox;

        $user = $request->user();
        $integrationIds = $user->integrations()->pluck('id')->all();

        $events = collect();
        if (! empty($integrationIds)) {
            $events = Event::query()
                ->whereIn('integration_id', $integrationIds)
                ->where(function ($query) use ($neLat, $swLat, $neLng, $swLng) {
                    $query
                        ->where(function ($query) use ($neLat, $swLat, $neLng, $swLng) {
                            $query->hasLocation()
                                ->withinBounds($neLat, $swLat, $neLng, $swLng);
                        })
                        ->orWhereHas('target', function ($query) use ($neLat, $swLat, $neLng, $swLng) {
                            $query->hasLocation()
                                ->withinBounds($neLat, $swLat, $neLng, $swLng);
                        });
                })
                ->with(['actor', 'target'])
                ->orderBy('time', 'desc')
                ->limit(self::CLUSTER_THRESHOLD + 1)
                ->get();
        }

        $places = EventObject::query()
            ->where('user_id', $user->id)
            ->where('concept', 'place')
            ->withinBounds($neLat, $swLat, $neLng, $swLng)
            ->limit(self::CLUSTER_THRESHOLD + 1)
            ->get();

        $total = $events->count() + $places->count();

        if ($total > self::CLUSTER_THRESHOLD) {
            return response()->json([
                'clusters' => $this->cluster($events, $places),
                'markers' => [],
            ]);
        }

        return response()->json([
            'clusters' => [],
            'markers' => [
                'events' => $events
                    ->map(fn (Event $event) => $this->eventMarker($event))
                    ->filter()
                    ->values()
                    ->all(),
                'places' => $places
                    ->map(fn (EventObject $place) => $this->placeMarker($place))
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    protected function parseBbox(string $input): ?array
    {
        if ($input === '') {
            return null;
        }

        $parts = explode(',', $input);
        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $p) {
            if (! is_numeric(trim($p))) {
                return null;
            }
        }

        [$swLat, $swLng, $neLat, $neLng] = array_map(fn ($v) => (float) trim($v), $parts);

        // TODO: Anti-meridian crossings (swLng > neLng) are not yet supported and require special handling (wraparound longitude logic)
        if ($swLat >= $neLat) {
            return null;
        }

        return [$swLat, $swLng, $neLat, $neLng];
    }

    /**
     * Coarse grid cluster — rounds each point to 2 decimal places (~1 km) and
     * counts occupants. Adequate for the "don't render 10k pins" zoom level.
     *
     * @return array<int, array{lat: float, lng: float, count: int}>
     */
    protected function cluster($events, $places): array
    {
        $buckets = [];

        foreach ($events as $event) {
            $coordinates = $this->eventCoordinates($event);

            if ($coordinates === null) {
                continue;
            }

            $this->bucket($buckets, $coordinates['lat'], $coordinates['lng']);
        }

        foreach ($places as $place) {
            $coordinates = $this->objectCoordinates($place);

            if ($coordinates === null) {
                continue;
            }

            $this->bucket($buckets, $coordinates['lat'], $coordinates['lng']);
        }

        return array_values($buckets);
    }

    /**
     * @param  array<string, array{lat: float, lng: float, count: int}>  $buckets
     */
    protected function bucket(array &$buckets, float $lat, float $lng): void
    {
        $key = round($lat, 2) . ',' . round($lng, 2);

        if (! isset($buckets[$key])) {
            $buckets[$key] = ['lat' => round($lat, 2), 'lng' => round($lng, 2), 'count' => 0];
        }

        $buckets[$key]['count']++;
    }

    /**
     * @return array{id: string, kind: string, lat: float, lng: float, title: string, subtitle: string|null, time: string|null, service: string|null}|null
     */
    protected function eventMarker(Event $event): ?array
    {
        $coordinates = $this->eventCoordinates($event);

        if ($coordinates === null) {
            return null;
        }

        return [
            'id' => $event->id,
            'kind' => $this->eventKind($event),
            'lat' => $coordinates['lat'],
            'lng' => $coordinates['lng'],
            'title' => $this->eventTitle($event),
            'subtitle' => $this->eventSubtitle($event),
            'time' => $event->time?->toIso8601String(),
            'service' => $event->service,
        ];
    }

    /**
     * @return array{id: string, kind: string, lat: float, lng: float, title: string, subtitle: null, time: null, service: null}|null
     */
    protected function placeMarker(EventObject $place): ?array
    {
        $coordinates = $this->objectCoordinates($place);

        if ($coordinates === null) {
            return null;
        }

        return [
            'id' => $place->id,
            'kind' => 'place',
            'lat' => $coordinates['lat'],
            'lng' => $coordinates['lng'],
            'title' => $place->title,
            'subtitle' => null,
            'time' => null,
            'service' => null,
        ];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    protected function eventCoordinates(Event $event): ?array
    {
        return $this->coordinatesFromModel($event)
            ?? ($event->relationLoaded('target') && $event->target
                ? $this->objectCoordinates($event->target)
                : null);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    protected function objectCoordinates(EventObject $object): ?array
    {
        return $this->coordinatesFromModel($object);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    protected function coordinatesFromModel(Event|EventObject $model): ?array
    {
        $lat = $model->latitude;
        $lng = $model->longitude;

        if (($lat === null || $lng === null) && $model instanceof EventObject) {
            $metadata = is_array($model->metadata) ? $model->metadata : [];
            $lat = $metadata['latitude'] ?? null;
            $lng = $metadata['longitude'] ?? null;
        }

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
        ];
    }

    protected function eventKind(Event $event): string
    {
        if ($event->domain === 'money') {
            return 'transaction';
        }

        if ($event->domain === 'health' && $event->action === 'did_workout') {
            return 'workout';
        }

        return 'event';
    }

    protected function eventTitle(Event $event): string
    {
        if ($event->target?->title) {
            return $event->target->title;
        }

        if ($event->actor?->title) {
            return $event->actor->title;
        }

        return format_action_title($event->action);
    }

    protected function eventSubtitle(Event $event): ?string
    {
        if ($event->value === null) {
            return null;
        }

        return format_event_display_value(
            $event->formatted_value,
            $event->value_unit,
            $event->service,
            $event->action,
        );
    }
}
