<?php

namespace App\Integrations\Oyster;

use App\Models\EventObject;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TflStationLookup
{
    private const TFL_API_BASE = 'https://api.tfl.gov.uk';

    private const CACHE_TTL = 86400; // 24 hours for successful lookups

    private const NEGATIVE_CACHE_TTL = 3600; // 1 hour for failed lookups

    /**
     * Get station location from TfL API or cache
     *
     * Returns array with: latitude, longitude, address, naptan_id
     */
    public function getStationLocation(string $stationName, ?string $mode = null): ?array
    {
        $normalizedName = $this->normalizeStationName($stationName);
        $cacheKey = "tfl_station:{$normalizedName}";

        // Check local cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached ?: null; // Handle cached nulls
        }

        // Query TfL API
        $result = $this->searchTflApi($stationName, $mode);

        // Cache result with appropriate TTL
        // Use shorter TTL for failed lookups to allow retry after transient failures
        $ttl = $result ? self::CACHE_TTL : self::NEGATIVE_CACHE_TTL;
        Cache::put($cacheKey, $result ?? false, $ttl);

        return $result;
    }

    /**
     * Normalize station name for comparison and caching
     */
    public function normalizeStationName(string $name): string
    {
        // Convert to lowercase
        $name = strtolower($name);

        // Remove common suffixes
        $suffixes = [
            ' station',
            ' underground',
            ' underground station',
            ' rail station',
            ' dlr',
            ' dlr station',
            ' overground',
            ' tram stop',
            ' bus station',
        ];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $name = substr($name, 0, -strlen($suffix));
            }
        }

        // Remove special characters
        $name = preg_replace('/[^a-z0-9\s]/', '', $name);

        // Normalize whitespace
        $name = preg_replace('/\s+/', ' ', trim($name));

        return $name;
    }

    /**
     * Get or create a station EventObject
     */
    public function getOrCreateStationObject(string $stationName, string $userId): EventObject
    {
        $normalizedTitle = $this->formatStationTitle($stationName);

        // Atomically get or create station using normalized title
        // The unique constraint on (user_id, concept, type, LOWER(title)) prevents duplicates
        $station = EventObject::firstOrCreate(
            [
                'user_id' => $userId,
                'concept' => 'place',
                'type' => 'tfl_station',
                'title' => $normalizedTitle,
            ],
            [
                'time' => now(),
                'metadata' => [
                    'category' => 'transport',
                    'original_name' => $stationName,
                ],
            ]
        );

        // Try to geocode the station if it doesn't have location data
        if (! $station->location) {
            $location = $this->getStationLocation($stationName);

            if ($location && $location['latitude'] && $location['longitude']) {
                $station->setLocation(
                    $location['latitude'],
                    $location['longitude'],
                    $location['address'] ?? $normalizedTitle,
                    'tfl_api'
                );

                // Update metadata with TfL data
                $station->metadata = array_merge($station->metadata ?? [], array_filter([
                    'naptan_id' => $location['naptan_id'] ?? null,
                    'tfl_modes' => $location['modes'] ?? null,
                    'zone' => $location['zone'] ?? null,
                ]));
                $station->save();
            }
        }

        return $station;
    }

    /**
     * Map transport mode constant to TfL API mode string
     */
    public function modeToTflApiMode(string $mode): string
    {
        return match ($mode) {
            OysterTransportModeDetector::MODE_TUBE => 'tube',
            OysterTransportModeDetector::MODE_DLR => 'dlr',
            OysterTransportModeDetector::MODE_OVERGROUND => 'overground',
            OysterTransportModeDetector::MODE_ELIZABETH => 'elizabeth-line',
            OysterTransportModeDetector::MODE_TRAM => 'tram',
            OysterTransportModeDetector::MODE_NATIONAL_RAIL => 'national-rail',
            OysterTransportModeDetector::MODE_BUS => 'bus',
            OysterTransportModeDetector::MODE_CABLE_CAR => 'cable-car',
            OysterTransportModeDetector::MODE_RIVER_BUS => 'river-bus',
            default => 'tube,dlr,overground,elizabeth-line,tram,national-rail',
        };
    }

    /**
     * Search TfL API for station by name
     */
    private function searchTflApi(string $stationName, ?string $mode): ?array
    {
        try {
            // TfL API allows up to 500 requests per minute without authentication
            $modes = $mode ?? 'tube,dlr,overground,elizabeth-line,tram,national-rail,bus';

            $response = Http::timeout(10)
                ->get(self::TFL_API_BASE . '/StopPoint/Search', [
                    'query' => $stationName,
                    'modes' => $modes,
                    'maxResults' => 5,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $matches = $data['matches'] ?? [];

                if (! empty($matches)) {
                    // Try to find the best match
                    $match = $this->findBestMatch($matches, $stationName);

                    if ($match) {
                        return [
                            'latitude' => $match['lat'] ?? null,
                            'longitude' => $match['lon'] ?? null,
                            'address' => $match['name'] ?? $stationName,
                            'naptan_id' => $match['id'] ?? null,
                            'modes' => $match['modes'] ?? [],
                            'zone' => $match['zone'] ?? null,
                        ];
                    }
                }
            }

            Log::debug('TflStationLookup: No results from API', [
                'station' => $stationName,
                'status' => $response->status(),
            ]);
        } catch (Exception $e) {
            Log::warning('TflStationLookup: API request failed', [
                'station' => $stationName,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Find the best matching station from search results
     */
    private function findBestMatch(array $matches, string $searchName): ?array
    {
        $normalizedSearch = $this->normalizeStationName($searchName);

        // First pass: exact name match
        foreach ($matches as $match) {
            $matchName = $this->normalizeStationName($match['name'] ?? '');
            if ($matchName === $normalizedSearch) {
                return $match;
            }
        }

        // Second pass: name contains search term
        foreach ($matches as $match) {
            $matchName = strtolower($match['name'] ?? '');
            if (str_contains($matchName, $normalizedSearch)) {
                return $match;
            }
        }

        // Fallback: return first result
        return $matches[0] ?? null;
    }

    /**
     * Format station name for display as title
     */
    private function formatStationTitle(string $name): string
    {
        // Clean up the name
        $name = trim($name);

        // Remove mode annotations in brackets
        $name = preg_replace('/\s*\[.*?\]\s*/', '', $name);

        // Remove common suffixes that don't add value
        $name = preg_replace('/\s+(tram stop|station)$/i', '', $name);

        // Title case
        $name = ucwords(strtolower($name));

        // Handle special cases
        $name = str_replace("'S", "'s", $name);
        $name = str_replace(' Dlr', ' DLR', $name);

        return trim($name);
    }
}
