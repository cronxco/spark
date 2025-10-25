<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MetricStatistic extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'service',
        'action',
        'value_unit',
        'event_count',
        'first_event_at',
        'last_event_at',
        'min_value',
        'max_value',
        'mean_value',
        'stddev_value',
        'normal_lower_bound',
        'normal_upper_bound',
        'last_calculated_at',
    ];

    protected $casts = [
        'event_count' => 'integer',
        'first_event_at' => 'datetime',
        'last_event_at' => 'datetime',
        'min_value' => 'decimal:6',
        'max_value' => 'decimal:6',
        'mean_value' => 'decimal:6',
        'stddev_value' => 'decimal:6',
        'normal_lower_bound' => 'decimal:6',
        'normal_upper_bound' => 'decimal:6',
        'last_calculated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trends(): HasMany
    {
        return $this->hasMany(MetricTrend::class);
    }

    /**
     * Scope to get metrics with sufficient data for analysis (≥30 days)
     */
    public function scopeWithSufficientData($query)
    {
        return $query->whereNotNull('first_event_at')
            ->whereNotNull('last_event_at')
            ->whereRaw('EXTRACT(EPOCH FROM (last_event_at - first_event_at)) >= ?', [30 * 24 * 60 * 60]);
    }

    /**
     * Scope to get metrics that need recalculation
     */
    public function scopeNeedsRecalculation($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_calculated_at')
                ->orWhere('last_calculated_at', '<', now()->subHour());
        });
    }

    /**
     * Get display name for the metric
     */
    public function getDisplayName(): string
    {
        return format_action_title($this->action);
    }

    /**
     * Get metric identifier string
     */
    public function getIdentifier(): string
    {
        return "{$this->service}.{$this->action}.{$this->value_unit}";
    }

    /**
     * Check if this metric has valid statistics for anomaly detection
     */
    public function hasValidStatistics(): bool
    {
        return $this->event_count >= 10
            && $this->mean_value !== null
            && $this->stddev_value !== null
            && $this->normal_lower_bound !== null
            && $this->normal_upper_bound !== null;
    }
}
