<?php

namespace App\Models;

use App\Services\RelationshipTypeRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Relationship extends Model
{
    use HasFactory;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'from_type',
        'from_id',
        'to_type',
        'to_id',
        'type',
        'value',
        'value_multiplier',
        'value_unit',
        'metadata',
    ];

    protected $casts = [
        'value' => 'integer',
        'value_multiplier' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'value_multiplier' => 1,
    ];

    /**
     * Create a relationship, handling bi-directional types intelligently.
     * For bi-directional types, prevents creating duplicate reverse relationships.
     * For directional types, prevents creating exact duplicates.
     */
    public static function createRelationship(array $attributes): self
    {
        $type = $attributes['type'];
        $isDirectional = RelationshipTypeRegistry::isDirectional($type);

        // For bi-directional relationships, check if reverse already exists
        if (! $isDirectional) {
            $existing = self::where('user_id', $attributes['user_id'])
                ->where(function ($query) use ($attributes) {
                    // Check both directions
                    $query->where(function ($q) use ($attributes) {
                        $q->where('from_type', $attributes['from_type'])
                            ->where('from_id', $attributes['from_id'])
                            ->where('to_type', $attributes['to_type'])
                            ->where('to_id', $attributes['to_id']);
                    })->orWhere(function ($q) use ($attributes) {
                        $q->where('from_type', $attributes['to_type'])
                            ->where('from_id', $attributes['to_id'])
                            ->where('to_type', $attributes['from_type'])
                            ->where('to_id', $attributes['from_id']);
                    });
                })
                ->where('type', $type)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // For directional relationships, use firstOrCreate to prevent exact duplicates
        return self::firstOrCreate(
            [
                'user_id' => $attributes['user_id'],
                'from_type' => $attributes['from_type'],
                'from_id' => $attributes['from_id'],
                'to_type' => $attributes['to_type'],
                'to_id' => $attributes['to_id'],
                'type' => $type,
            ],
            $attributes
        );
    }

    /**
     * Find or create a relationship.
     */
    public static function findOrCreateRelationship(array $attributes, array $values = []): self
    {
        $type = $attributes['type'];
        $isDirectional = RelationshipTypeRegistry::isDirectional($type);

        // For bi-directional relationships, check both directions
        if (! $isDirectional) {
            $existing = self::where('user_id', $attributes['user_id'])
                ->where(function ($query) use ($attributes) {
                    $query->where(function ($q) use ($attributes) {
                        $q->where('from_type', $attributes['from_type'])
                            ->where('from_id', $attributes['from_id'])
                            ->where('to_type', $attributes['to_type'])
                            ->where('to_id', $attributes['to_id']);
                    })->orWhere(function ($q) use ($attributes) {
                        $q->where('from_type', $attributes['to_type'])
                            ->where('from_id', $attributes['to_id'])
                            ->where('to_type', $attributes['from_type'])
                            ->where('to_id', $attributes['from_id']);
                    });
                })
                ->where('type', $type)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return self::firstOrCreate($attributes, $values);
    }

    /**
     * Get activity log options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'value', 'value_unit', 'metadata'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The "from" entity in the relationship (polymorphic).
     */
    public function from(): MorphTo
    {
        return $this->morphTo('from');
    }

    /**
     * The "to" entity in the relationship (polymorphic).
     */
    public function to(): MorphTo
    {
        return $this->morphTo('to');
    }

    /**
     * The user who owns this relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the formatted value (divided by multiplier).
     */
    public function getFormattedValueAttribute(): ?float
    {
        if ($this->value === null) {
            return null;
        }

        $multiplier = $this->value_multiplier ?? 1;

        return $this->value / $multiplier;
    }

    /**
     * Check if this relationship type is directional.
     */
    public function isDirectional(): bool
    {
        return RelationshipTypeRegistry::isDirectional($this->type);
    }

    /**
     * Get the type configuration from the registry.
     *
     * @return array{display_name: string, icon: string, is_directional: bool, description: string, supports_value: bool, default_value_unit?: string}|null
     */
    public function getTypeConfig(): ?array
    {
        return RelationshipTypeRegistry::getType($this->type);
    }

    /**
     * For bi-directional relationships, find the opposite relationship.
     * Returns null for directional relationships.
     */
    public function getOpposite(): ?self
    {
        if ($this->isDirectional()) {
            return null;
        }

        return self::where('user_id', $this->user_id)
            ->where('from_type', $this->to_type)
            ->where('from_id', $this->to_id)
            ->where('to_type', $this->from_type)
            ->where('to_id', $this->from_id)
            ->where('type', $this->type)
            ->first();
    }

    // ==========================================
    // Pending Relationship Support
    // ==========================================

    /**
     * Check if this relationship is pending review.
     */
    public function isPending(): bool
    {
        return ($this->metadata['pending'] ?? false) === true;
    }

    /**
     * Check if this relationship is confirmed (not pending).
     */
    public function isConfirmed(): bool
    {
        return ! $this->isPending();
    }

    /**
     * Get the confidence score for pending relationships.
     */
    public function getConfidence(): ?float
    {
        return $this->metadata['confidence'] ?? null;
    }

    /**
     * Get the detection strategy for pending relationships.
     */
    public function getDetectionStrategy(): ?string
    {
        return $this->metadata['detection_strategy'] ?? null;
    }

    /**
     * Get the matching criteria for pending relationships.
     */
    public function getMatchingCriteria(): ?array
    {
        return $this->metadata['matching_criteria'] ?? null;
    }

    /**
     * Approve a pending relationship.
     */
    public function approve(): self
    {
        $metadata = $this->metadata ?? [];
        unset($metadata['pending']);
        $metadata['approved_at'] = now()->toIso8601String();

        $this->update(['metadata' => $metadata]);

        return $this;
    }

    /**
     * Reject a pending relationship (soft delete it).
     */
    public function reject(): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['rejected_at'] = now()->toIso8601String();
        $this->update(['metadata' => $metadata]);

        $this->delete();
    }

    /**
     * Scope to get only pending relationships.
     */
    public function scopePending($query)
    {
        return $query->whereRaw("(metadata->>'pending')::boolean = true");
    }

    /**
     * Scope to get only confirmed (non-pending) relationships.
     */
    public function scopeConfirmed($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('metadata')
                ->orWhereRaw("metadata->>'pending' IS NULL")
                ->orWhereRaw("(metadata->>'pending')::boolean = false");
        });
    }

    /**
     * Scope to get relationships above a confidence threshold.
     */
    public function scopeAboveConfidence($query, float $threshold)
    {
        return $query->whereRaw("(metadata->>'confidence')::numeric >= ?", [$threshold]);
    }

    /**
     * Scope to get relationships for a specific detection strategy.
     */
    public function scopeForStrategy($query, string $strategy)
    {
        return $query->whereRaw("metadata->>'detection_strategy' = ?", [$strategy]);
    }

    /**
     * Scope to get relationships between two events (either direction).
     */
    public function scopeBetweenEvents($query, string $eventAId, string $eventBId)
    {
        return $query->where('from_type', Event::class)
            ->where('to_type', Event::class)
            ->where(function ($q) use ($eventAId, $eventBId) {
                $q->where(function ($inner) use ($eventAId, $eventBId) {
                    $inner->where('from_id', $eventAId)->where('to_id', $eventBId);
                })->orWhere(function ($inner) use ($eventAId, $eventBId) {
                    $inner->where('from_id', $eventBId)->where('to_id', $eventAId);
                });
            });
    }
}
