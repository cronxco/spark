<?php

namespace Tests\Unit\Models;

use App\Models\GeocodingCache;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeocodingCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function hash_address_normalizes_consistently(): void
    {
        $address1 = '123 Main St, London';
        $address2 = '123  MAIN  St,  LONDON';
        $address3 = '  123 Main St, London  ';

        $hash1 = GeocodingCache::hashAddress($address1);
        $hash2 = GeocodingCache::hashAddress($address2);
        $hash3 = GeocodingCache::hashAddress($address3);

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals($hash1, $hash3);
        $this->assertEquals(64, strlen($hash1)); // SHA-256 produces 64 char hex string
    }

    /**
     * @test
     */
    public function hash_address_different_for_different_addresses(): void
    {
        $hash1 = GeocodingCache::hashAddress('123 Main St, London');
        $hash2 = GeocodingCache::hashAddress('456 Other St, Manchester');

        $this->assertNotEquals($hash1, $hash2);
    }

    /**
     * @test
     */
    public function record_hit_increments_count(): void
    {
        $cache = GeocodingCache::create([
            'address_hash' => 'test_hash',
            'original_address' => 'Test Address',
            'formatted_address' => 'Test Address, UK',
            'location' => Point::makeGeodetic(51.5074, -0.1278),
            'country_code' => 'GB',
            'source' => 'geoapify',
            'hit_count' => 1,
            'last_used_at' => now()->subDay(),
        ]);

        $initialCount = $cache->hit_count;
        $initialTime = $cache->last_used_at;

        $cache->recordHit();
        $cache->refresh();

        $this->assertEquals($initialCount + 1, $cache->hit_count);
        $this->assertTrue($cache->last_used_at->isAfter($initialTime));
    }

    /**
     * @test
     */
    public function location_cast_to_point(): void
    {
        $cache = GeocodingCache::create([
            'address_hash' => 'test_hash',
            'original_address' => 'Test Address',
            'formatted_address' => 'Test Address, UK',
            'location' => Point::makeGeodetic(51.5074, -0.1278),
            'country_code' => 'GB',
            'source' => 'geoapify',
            'hit_count' => 1,
            'last_used_at' => now(),
        ]);

        $cache->refresh();

        $this->assertInstanceOf(Point::class, $cache->location);
        $this->assertEquals(51.5074, $cache->location->getLatitude());
        $this->assertEquals(-0.1278, $cache->location->getLongitude());
    }
}
