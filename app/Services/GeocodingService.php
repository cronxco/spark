<?php

namespace App\Services;

use App\Models\GeocodingCache;
use Clickbar\Magellan\Data\Geometries\Point;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Main geocoding method - checks cache first, then API
     */
    public function geocode(string $address): ?array
    {
        // Check cache first
        $cached = $this->getCachedGeocode($address);
        if ($cached) {
            return $cached;
        }

        // Check rate limit before making API call
        if (! $this->canMakeRequest()) {
            Log::warning('Geoapify rate limit reached', [
                'address' => $address,
                'status' => $this->getRateLimitStatus(),
            ]);

            return null;
        }

        // Geocode via API
        $result = $this->geocodeViaGeoapify($address);
        if ($result) {
            $this->cacheGeocode($address, $result);
            $this->incrementRequestCount();

            return $result;
        }

        return null;
    }

    /**
     * Reverse geocode coordinates to address - checks cache first, then API
     */
    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        // Check cache first using coordinate-based hash
        $cached = $this->getCachedReverseGeocode($latitude, $longitude);
        if ($cached) {
            return $cached;
        }

        // Check rate limit before making API call
        if (! $this->canMakeRequest()) {
            Log::warning('Geoapify rate limit reached', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'status' => $this->getRateLimitStatus(),
            ]);

            return null;
        }

        // Reverse geocode via API
        $result = $this->reverseGeocodeViaGeoapify($latitude, $longitude);
        if ($result) {
            $this->cacheReverseGeocode($latitude, $longitude, $result);
            $this->incrementRequestCount();

            return $result;
        }

        return null;
    }

    /**
     * Check geocoding cache for existing result
     */
    public function getCachedGeocode(string $address): ?array
    {
        $hash = GeocodingCache::hashAddress($address);

        $cached = GeocodingCache::where('address_hash', $hash)->first();

        if ($cached) {
            $cached->recordHit();

            return [
                'latitude' => $cached->location?->getLatitude(),
                'longitude' => $cached->location?->getLongitude(),
                'formatted_address' => $cached->formatted_address,
                'country_code' => $cached->country_code,
                'source' => 'cache',
            ];
        }

        return null;
    }

    /**
     * Geocode address using Geoapify API
     */
    public function geocodeViaGeoapify(string $address): ?array
    {
        $apiKey = config('services.geoapify.api_key');
        $baseUrl = config('services.geoapify.base_url');

        if (! $apiKey) {
            Log::error('Geoapify API key not configured');

            return null;
        }

        try {
            $response = Http::timeout(10)->get("{$baseUrl}/geocode/search", [
                'text' => $address,
                'apiKey' => $apiKey,
                'limit' => 1,
            ]);

            if (! $response->successful()) {
                Log::error('Geoapify API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            if (empty($data['features'])) {
                Log::info('Geoapify found no results', ['address' => $address]);

                return null;
            }

            $feature = $data['features'][0];
            $properties = $feature['properties'];
            $coordinates = $feature['geometry']['coordinates']; // [lng, lat]

            return [
                'latitude' => $coordinates[1],
                'longitude' => $coordinates[0],
                'formatted_address' => $properties['formatted'] ?? null,
                'country_code' => $properties['country_code'] ?? null,
                'source' => 'geoapify',
            ];
        } catch (Exception $e) {
            Log::error('Geoapify geocoding exception', [
                'address' => $address,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Store geocoding result in cache
     */
    public function cacheGeocode(string $address, array $result): void
    {
        $hash = GeocodingCache::hashAddress($address);

        GeocodingCache::updateOrCreate(
            ['address_hash' => $hash],
            [
                'original_address' => $address,
                'formatted_address' => $result['formatted_address'],
                'location' => Point::makeGeodetic($result['latitude'], $result['longitude']),
                'country_code' => isset($result['country_code']) ? strtoupper($result['country_code']) : null,
                'source' => $result['source'],
                'last_used_at' => now(),
            ]
        );
    }

    /**
     * Check if we can make another API request today
     */
    public function canMakeRequest(): bool
    {
        $status = $this->getRateLimitStatus();

        return $status['remaining'] > 0;
    }

    /**
     * Increment the request count for today
     */
    public function incrementRequestCount(): void
    {
        $key = $this->getRateLimitKey();
        $count = Cache::get($key, 0);

        // Store until end of day (UTC)
        $expiresAt = now()->endOfDay();

        Cache::put($key, $count + 1, $expiresAt);
    }

    /**
     * Get rate limit status
     */
    public function getRateLimitStatus(): array
    {
        $limit = config('services.geoapify.daily_limit', 3000);
        $used = Cache::get($this->getRateLimitKey(), 0);

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    /**
     * Check reverse geocoding cache for existing result
     */
    public function getCachedReverseGeocode(float $latitude, float $longitude): ?array
    {
        // Round to 5 decimal places (~1.1m precision) for cache lookup
        $lat = round($latitude, 5);
        $lng = round($longitude, 5);

        $cached = GeocodingCache::query()
            ->whereRaw('ST_DWithin(location::geography, ST_MakePoint(?, ?)::geography, 10)', [$lng, $lat])
            ->orderByRaw('ST_Distance(location::geography, ST_MakePoint(?, ?)::geography)', [$lng, $lat])
            ->first();

        if ($cached) {
            $cached->recordHit();

            return [
                'latitude' => $cached->location?->getLatitude(),
                'longitude' => $cached->location?->getLongitude(),
                'formatted_address' => $cached->formatted_address,
                'country_code' => $cached->country_code,
                'source' => 'cache',
            ];
        }

        return null;
    }

    /**
     * Reverse geocode coordinates using Geoapify API
     */
    public function reverseGeocodeViaGeoapify(float $latitude, float $longitude): ?array
    {
        $apiKey = config('services.geoapify.api_key');
        $baseUrl = config('services.geoapify.base_url');

        if (! $apiKey) {
            Log::error('Geoapify API key not configured');

            return null;
        }

        try {
            $response = Http::timeout(10)->get("{$baseUrl}/geocode/reverse", [
                'lat' => $latitude,
                'lon' => $longitude,
                'apiKey' => $apiKey,
                'limit' => 1,
            ]);

            if (! $response->successful()) {
                Log::error('Geoapify reverse geocode API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            if (empty($data['features'])) {
                Log::info('Geoapify reverse geocode found no results', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);

                return null;
            }

            $feature = $data['features'][0];
            $properties = $feature['properties'];
            $coordinates = $feature['geometry']['coordinates']; // [lng, lat]

            return [
                'latitude' => $coordinates[1],
                'longitude' => $coordinates[0],
                'formatted_address' => $properties['formatted'] ?? null,
                'country_code' => isset($properties['country_code']) ? strtoupper($properties['country_code']) : null,
                'source' => 'geoapify',
                'address_components' => [
                    'name' => $properties['name'] ?? null,
                    'street' => $properties['street'] ?? null,
                    'housenumber' => $properties['housenumber'] ?? null,
                    'postcode' => $properties['postcode'] ?? null,
                    'city' => $properties['city'] ?? null,
                    'state' => $properties['state'] ?? null,
                    'country' => $properties['country'] ?? null,
                ],
            ];
        } catch (Exception $e) {
            Log::error('Geoapify reverse geocoding exception', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Store reverse geocoding result in cache
     */
    public function cacheReverseGeocode(float $latitude, float $longitude, array $result): void
    {
        // Use rounded coordinates for consistent caching
        $lat = round($latitude, 5);
        $lng = round($longitude, 5);

        // Create pseudo-address for hash
        $pseudoAddress = "{$lat},{$lng}";
        $hash = GeocodingCache::hashAddress($pseudoAddress);

        GeocodingCache::updateOrCreate(
            ['address_hash' => $hash],
            [
                'original_address' => $result['formatted_address'] ?? $pseudoAddress,
                'formatted_address' => $result['formatted_address'],
                'location' => Point::makeGeodetic($result['latitude'], $result['longitude']),
                'country_code' => isset($result['country_code']) ? strtoupper($result['country_code']) : null,
                'source' => $result['source'],
                'last_used_at' => now(),
            ]
        );
    }

    /**
     * Get cache key for rate limiting
     */
    private function getRateLimitKey(): string
    {
        return 'geoapify_requests_' . now()->format('Y-m-d');
    }
}
