<?php

namespace App\Models;

use App\Services\RelationshipTypeRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingTransactionLink extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_AUTO_APPROVED = 'auto_approved';

    protected $fillable = [
        'user_id',
        'source_event_id',
        'target_event_id',
        'relationship_type',
        'confidence',
        'detection_strategy',
        'matching_criteria',
        'status',
        'value',
        'value_multiplier',
        'value_unit',
        'metadata',
        'created_relationship_id',
        'reviewed_at',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'matching_criteria' => 'array',
        'metadata' => 'array',
        'value' => 'integer',
        'value_multiplier' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'value_multiplier' => 1,
    ];

    /**
     * The user who owns this pending link.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * The source event in the potential relationship.
     */
    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'source_event_id');
    }

    /**
     * The target event in the potential relationship.
     */
    public function targetEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'target_event_id');
    }

    /**
     * The created relationship (if approved).
     */
    public function createdRelationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class, 'created_relationship_id');
    }

    /**
     * Approve this pending link and create the relationship.
     */
    public function approve(): Relationship
    {
        $relationship = Relationship::createRelationship([
            'user_id' => $this->user_id,
            'from_type' => Event::class,
            'from_id' => $this->source_event_id,
            'to_type' => Event::class,
            'to_id' => $this->target_event_id,
            'type' => $this->relationship_type,
            'value' => $this->value,
            'value_multiplier' => $this->value_multiplier,
            'value_unit' => $this->value_unit,
            'metadata' => array_merge($this->metadata ?? [], [
                'auto_linked' => true,
                'detection_strategy' => $this->detection_strategy,
                'confidence' => $this->confidence,
                'matching_criteria' => $this->matching_criteria,
            ]),
        ]);

        $this->update([
            'status' => self::STATUS_APPROVED,
            'created_relationship_id' => $relationship->id,
            'reviewed_at' => now(),
        ]);

        return $relationship;
    }

    /**
     * Reject this pending link.
     */
    public function reject(): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Check if this pending link is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this pending link was approved.
     */
    public function isApproved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_AUTO_APPROVED]);
    }

    /**
     * Check if this pending link was rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Get the relationship type configuration.
     */
    public function getTypeConfig(): ?array
    {
        return RelationshipTypeRegistry::getType($this->relationship_type);
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
     * Scope to get pending links only.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get links above a confidence threshold.
     */
    public function scopeAboveConfidence($query, float $threshold)
    {
        return $query->where('confidence', '>=', $threshold);
    }

    /**
     * Scope to get links for a specific detection strategy.
     */
    public function scopeForStrategy($query, string $strategy)
    {
        return $query->where('detection_strategy', $strategy);
    }
}
