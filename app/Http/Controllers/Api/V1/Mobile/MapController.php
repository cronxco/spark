<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactPlaceResource;
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
                ->hasLocation()
                ->withinBounds($neLat, $swLat, $neLng, $swLng)
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
                'events' => CompactEventResource::collection($events)->resolve($request),
                'places' => CompactPlaceResource::collection($places)->resolve($request),
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

        if ($swLat >= $neLat || $swLng >= $neLng) {
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
            $lat = $event->latitude;
            $lng = $event->longitude;
            if ($lat === null || $lng === null) {
                continue;
            }
            $this->bucket($buckets, $lat, $lng);
        }

        foreach ($places as $place) {
            $meta = is_array($place->metadata) ? $place->metadata : [];
            if (! isset($meta['latitude'], $meta['longitude'])) {
                continue;
            }
            $this->bucket($buckets, (float) $meta['latitude'], (float) $meta['longitude']);
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
}
