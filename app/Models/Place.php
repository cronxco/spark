<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Place extends EventObject
{
    /**
     * Apply global scope to filter only place objects
     */
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('places', function (Builder $query) {
            $query->where('concept', 'place');
        });

        static::creating(function ($model) {
            $model->concept = 'place';
        });
    }

    /**
     * Check if a given lat/lng is within this place's radius
     */
    public function isNearLocation(float $latitude, float $longitude, int $radiusMeters = 50): bool
    {
        if (! $this->location) {
            return false;
        }

        return DB::selectOne(
            'SELECT ST_DWithin(
                location::geography,
                ST_MakePoint(?, ?)::geography,
                ?
            ) as within',
            [$longitude, $latitude, $radiusMeters]
        )->within ?? false;
    }

    /**
     * Override parent's relationshipsTo to use EventObject::class
     * Since Place extends EventObject and shares the same table,
     * relationships are stored with to_type = EventObject::class
     */
    public function relationshipsTo()
    {
        return $this->hasMany(Relationship::class, 'to_id')
            ->where('to_type', EventObject::class)
            ->withTrashed();
    }

    /**
     * Override parent's relationshipsFrom to use EventObject::class
     */
    public function relationshipsFrom()
    {
        return $this->hasMany(Relationship::class, 'from_id')
            ->where('from_type', EventObject::class)
            ->withTrashed();
    }

    /**
     * Get all events that occurred at this place
     */
    public function eventsHere()
    {
        return Event::query()
            ->where('user_id', $this->user_id)
            ->hasLocation()
            ->whereHas('relationshipsFrom', function ($query) {
                $query->where('to_id', $this->id)
                    ->where('to_type', EventObject::class)
                    ->where('type', 'occurred_at');
            })
            ->orderByDesc('time');
    }

    /**
     * Get all events within radius of this place (fallback if no relationships)
     */
    public function eventsNearby(int $radiusMeters = 50)
    {
        if (! $this->location) {
            return Event::query()->whereRaw('1 = 0'); // Empty query
        }

        return Event::query()
            ->where('user_id', $this->user_id)
            ->withinRadius($this->latitude, $this->longitude, $radiusMeters)
            ->orderByDesc('time');
    }

    /**
     * Increment visit count in metadata
     */
    public function recordVisit(): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['visit_count'] = ($metadata['visit_count'] ?? 0) + 1;
        $metadata['last_visit_at'] = now()->toIso8601String();

        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Get visit count from metadata
     */
    public function getVisitCountAttribute(): int
    {
        return $this->metadata['visit_count'] ?? 0;
    }

    /**
     * Get last visit timestamp from metadata
     */
    public function getLastVisitAtAttribute(): ?string
    {
        return $this->metadata['last_visit_at'] ?? null;
    }

    /**
     * Get first visit timestamp from metadata
     */
    public function getFirstVisitAtAttribute(): ?string
    {
        return $this->metadata['first_visit_at'] ?? null;
    }

    /**
     * Get place category from metadata
     */
    public function getCategoryAttribute(): ?string
    {
        return $this->metadata['category'] ?? null;
    }

    /**
     * Set place category in metadata
     */
    public function setCategoryAttribute(?string $category): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['category'] = $category;
        $this->metadata = $metadata;
    }

    /**
     * Check if place is marked as favorite
     */
    public function getIsFavoriteAttribute(): bool
    {
        return $this->metadata['is_favorite'] ?? false;
    }

    /**
     * Set favorite status in metadata
     */
    public function setIsFavoriteAttribute(bool $isFavorite): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['is_favorite'] = $isFavorite;
        $this->metadata = $metadata;
    }

    /**
     * Get place detection radius from metadata (default 50m)
     */
    public function getDetectionRadiusAttribute(): int
    {
        return $this->metadata['detection_radius_meters'] ?? 50;
    }

    /**
     * Set detection radius in metadata
     */
    public function setDetectionRadiusAttribute(int $radiusMeters): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['detection_radius_meters'] = $radiusMeters;
        $this->metadata = $metadata;
    }

    /**
     * Scope to get places ordered by visit frequency
     */
    public function scopeOrderByVisitCount(Builder $query, string $direction = 'desc'): void
    {
        $query->orderByRaw("CAST(metadata->>'visit_count' AS INTEGER) {$direction} NULLS LAST");
    }

    /**
     * Scope to get favorite places
     */
    public function scopeFavorites(Builder $query): void
    {
        $query->whereRaw("metadata->>'is_favorite' = 'true'");
    }

    /**
     * Scope to get places by category
     */
    public function scopeByCategory(Builder $query, string $category): void
    {
        $query->whereRaw("metadata->>'category' = ?", [$category]);
    }
}
