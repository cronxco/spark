<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class Person extends EventObject
{
    /**
     * Apply global scope to filter only person objects
     */
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('people', function (Builder $query) {
            $query->where('concept', 'person');
        });

        static::creating(function ($model) {
            $model->concept = 'person';
        });
    }

    /**
     * Override relationshipsTo to use EventObject::class
     * Since Person extends EventObject and shares the same table,
     * relationships are stored with to_type = EventObject::class
     */
    public function relationshipsTo()
    {
        return $this->hasMany(Relationship::class, 'to_id')
            ->where('to_type', EventObject::class)
            ->withTrashed();
    }

    /**
     * Override relationshipsFrom to use EventObject::class
     */
    public function relationshipsFrom()
    {
        return $this->hasMany(Relationship::class, 'from_id')
            ->where('from_type', EventObject::class)
            ->withTrashed();
    }

    /**
     * Get all photo clusters this person is tagged in
     */
    public function photoClusters()
    {
        return EventObject::withoutGlobalScope('people')
            ->where('user_id', $this->user_id)
            ->where('concept', 'photo_cluster')
            ->whereHas('relationshipsTo', function ($query) {
                $query->where('from_id', $this->id)
                    ->where('from_type', EventObject::class)
                    ->where('type', 'tagged_in');
            })
            ->orderByDesc('time');
    }

    /**
     * Get photo count from metadata
     */
    public function getPhotoCountAttribute(): int
    {
        return $this->metadata['face_count'] ?? 0;
    }

    /**
     * Scope to get non-hidden people
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereRaw("(metadata->>'is_hidden')::boolean = false OR metadata->>'is_hidden' IS NULL");
    }

    /**
     * Scope to order by photo count
     */
    public function scopeOrderByPhotoCount(Builder $query, string $direction = 'desc'): void
    {
        // Validate and normalize direction to prevent SQL injection
        $validatedDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $query->orderByRaw("CAST(metadata->>'face_count' AS INTEGER) {$validatedDirection} NULLS LAST");
    }

    /**
     * Check if person is marked as hidden
     */
    public function getIsHiddenAttribute(): bool
    {
        return $this->metadata['is_hidden'] ?? false;
    }

    /**
     * Get birth date from metadata
     */
    public function getBirthDateAttribute(): ?string
    {
        return $this->metadata['birth_date'] ?? null;
    }

    /**
     * Get Immich person ID from metadata
     */
    public function getImmichPersonIdAttribute(): ?string
    {
        return $this->metadata['immich_person_id'] ?? null;
    }
}
