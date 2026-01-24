<?php

namespace Tests\Unit\Services;

use App\Models\GeocodingCache;
use App\Services\GeocodingService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GeocodingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeocodingService;
        Cache::flush();
    }

    /**
     * @test
     */
    public function geocode_returns_cached_result_when_available(): void
    {
        // Create cached geocode
        $address = '123 Main St, London';
        GeocodingCache::create([
            'address_hash' => GeocodingCache::hashAddress($address),
            'original_address' => $address,
            'formatted_address' => '123 Main St, London, UK',
            'location' => Point::makeGeodetic(51.5074, -0.1278),
            'country_code' => 'GB',
            'source' => 'geoapify',
            'hit_count' => 1,
            'last_used_at' => now(),
        ]);

        // Geocode should return cached result without HTTP call
        Http::fake();

        $result = $this->service->geocode($address);

        Http::assertNothingSent();
        $this->assertEquals('cache', $result['source']);
        $this->assertEquals(51.5074, $result['latitude']);
        $this->assertEquals(-0.1278, $result['longitude']);
        $this->assertEquals('123 Main St, London, UK', $result['formatted_address']);
    }

    /**
     * @test
     */
    public function geocode_increments_cache_hit_count(): void
    {
        $address = '123 Main St, London';
        $cache = GeocodingCache::create([
            'address_hash' => GeocodingCache::hashAddress($address),
            'original_address' => $address,
            'formatted_address' => '123 Main St, London, UK',
            'location' => Point::makeGeodetic(51.5074, -0.1278),
            'country_code' => 'GB',
            'source' => 'geoapify',
            'hit_count' => 1,
            'last_used_at' => now()->subDay(),
        ]);

        Http::fake();

        $this->service->geocode($address);

        $cache->refresh();
        $this->assertEquals(2, $cache->hit_count);
    }

    /**
     * @test
     */
    public function geocode_calls_geoapify_when_not_cached(): void
    {
        config(['services.geoapify.api_key' => 'test_api_key']);
        config(['services.geoapify.base_url' => 'https://api.geoapify.com/v1']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [
                    [
                        'geometry' => [
                            'coordinates' => [-0.1278, 51.5074], // [lng, lat]
                        ],
                        'properties' => [
                            'formatted' => '123 Main St, London, UK',
                            'country_code' => 'gb',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->geocode('123 Main St, London');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.geoapify.com') &&
                   str_contains($request->url(), 'geocode/search');
        });

        $this->assertEquals('geoapify', $result['source']);
        $this->assertEquals(51.5074, $result['latitude']);
        $this->assertEquals(-0.1278, $result['longitude']);
    }

    /**
     * @test
     */
    public function geocode_caches_api_result(): void
    {
        config(['services.geoapify.api_key' => 'test_api_key']);
        config(['services.geoapify.base_url' => 'https://api.geoapify.com/v1']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [
                    [
                        'geometry' => [
                            'coordinates' => [-0.1278, 51.5074],
                        ],
                        'properties' => [
                            'formatted' => '123 Main St, London, UK',
                            'country_code' => 'gb',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $address = '123 Main St, London';
        $this->service->geocode($address);

        $cached = GeocodingCache::where('address_hash', GeocodingCache::hashAddress($address))->first();

        $this->assertNotNull($cached);
        $this->assertEquals('123 Main St, London, UK', $cached->formatted_address);
        $this->assertEquals('GB', $cached->country_code);
    }

    /**
     * @test
     */
    public function geocode_returns_null_when_no_results(): void
    {
        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $result = $this->service->geocode('Invalid Address XYZ123');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function geocode_returns_null_when_api_fails(): void
    {
        Http::fake([
            'api.geoapify.com/*' => Http::response([], 500),
        ]);

        $result = $this->service->geocode('123 Main St, London');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function can_make_request_returns_true_when_under_limit(): void
    {
        Cache::put('geoapify_requests_'.now()->format('Y-m-d'), 100, now()->endOfDay());

        $this->assertTrue($this->service->canMakeRequest());
    }

    /**
     * @test
     */
    public function can_make_request_returns_false_when_at_limit(): void
    {
        Cache::put('geoapify_requests_'.now()->format('Y-m-d'), 3000, now()->endOfDay());

        $this->assertFalse($this->service->canMakeRequest());
    }

    /**
     * @test
     */
    public function increment_request_count(): void
    {
        $this->service->incrementRequestCount();

        $count = Cache::get('geoapify_requests_'.now()->format('Y-m-d'));
        $this->assertEquals(1, $count);

        $this->service->incrementRequestCount();
        $count = Cache::get('geoapify_requests_'.now()->format('Y-m-d'));
        $this->assertEquals(2, $count);
    }

    /**
     * @test
     */
    public function get_rate_limit_status(): void
    {
        Cache::put('geoapify_requests_'.now()->format('Y-m-d'), 150, now()->endOfDay());

        $status = $this->service->getRateLimitStatus();

        $this->assertEquals(150, $status['used']);
        $this->assertEquals(3000, $status['limit']);
        $this->assertEquals(2850, $status['remaining']);
    }

    /**
     * @test
     */
    public function geocode_respects_rate_limit(): void
    {
        // Set cache to limit
        Cache::put('geoapify_requests_'.now()->format('Y-m-d'), 3000, now()->endOfDay());

        Http::fake();

        $result = $this->service->geocode('123 Main St, London');

        // Should not make API call when at limit
        Http::assertNothingSent();
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function reverse_geocode_returns_cached_result_when_nearby(): void
    {
        // Create cached result
        GeocodingCache::create([
            'address_hash' => GeocodingCache::hashAddress('51.5074,-0.1278'),
            'original_address' => 'Starbucks, 123 Main St, London',
            'formatted_address' => 'Starbucks, 123 Main St, London, UK',
            'location' => Point::makeGeodetic(51.5074, -0.1278),
            'country_code' => 'GB',
            'source' => 'geoapify',
            'hit_count' => 1,
            'last_used_at' => now(),
        ]);

        Http::fake();

        // Query nearby coordinates (within 10m)
        $result = $this->service->reverseGeocode(51.50741, -0.12781);

        Http::assertNothingSent();
        $this->assertEquals('cache', $result['source']);
        $this->assertNotNull($result['formatted_address']);
    }

    /**
     * @test
     */
    public function reverse_geocode_calls_api_when_no_cache(): void
    {
        config(['services.geoapify.api_key' => 'test_api_key']);
        config(['services.geoapify.base_url' => 'https://api.geoapify.com/v1']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [
                    [
                        'properties' => [
                            'formatted' => 'Starbucks, 123 Main St, London, UK',
                            'country_code' => 'gb',
                            'name' => 'Starbucks',
                            'street' => 'Main St',
                            'city' => 'London',
                        ],
                        'geometry' => [
                            'coordinates' => [-0.1278, 51.5074],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = $this->service->reverseGeocode(51.5074, -0.1278);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.geoapify.com/v1/geocode/reverse') &&
                   $request['lat'] == 51.5074 &&
                   $request['lon'] == -0.1278;
        });

        $this->assertEquals('geoapify', $result['source']);
        $this->assertEquals('Starbucks, 123 Main St, London, UK', $result['formatted_address']);
        $this->assertEquals('GB', $result['country_code']);
        $this->assertEquals('Starbucks', $result['address_components']['name']);
    }

    /**
     * @test
     */
    public function reverse_geocode_caches_result(): void
    {
        config(['services.geoapify.api_key' => 'test_api_key']);
        config(['services.geoapify.base_url' => 'https://api.geoapify.com/v1']);

        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [
                    [
                        'properties' => [
                            'formatted' => 'Starbucks, 123 Main St, London, UK',
                            'country_code' => 'gb',
                        ],
                        'geometry' => [
                            'coordinates' => [-0.1278, 51.5074],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->assertEquals(0, GeocodingCache::count());

        $this->service->reverseGeocode(51.5074, -0.1278);

        $this->assertEquals(1, GeocodingCache::count());

        $cache = GeocodingCache::first();
        $this->assertEquals('Starbucks, 123 Main St, London, UK', $cache->formatted_address);
    }

    /**
     * @test
     */
    public function reverse_geocode_respects_rate_limit(): void
    {
        Cache::put('geoapify_requests_'.now()->format('Y-m-d'), 3000, now()->endOfDay());

        Http::fake();

        $result = $this->service->reverseGeocode(51.5074, -0.1278);

        Http::assertNothingSent();
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function reverse_geocode_returns_null_when_no_results(): void
    {
        Http::fake([
            'api.geoapify.com/*' => Http::response([
                'features' => [],
            ]),
        ]);

        $result = $this->service->reverseGeocode(0, 0);

        $this->assertNull($result);
    }
}
