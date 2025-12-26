<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;

class GeocodingCache extends Model
{
    protected $table = 'geocoding_cache';

    protected $fillable = [
        'address_hash',
        'original_address',
        'formatted_address',
        'location',
        'country_code',
        'source',
        'hit_count',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'hit_count' => 'integer',
        'location' => Point::class,
    ];

    /**
     * Generate normalized hash for address lookup
     */
    public static function hashAddress(string $address): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $address)));

        return hash('sha256', $normalized);
    }

    /**
     * Record cache hit
     */
    public function recordHit(): void
    {
        $this->increment('hit_count');
        $this->update(['last_used_at' => now()]);
    }
}
