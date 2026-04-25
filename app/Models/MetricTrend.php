<?php

namespace App\Models;

use App\Events\Mobile\AnomalyRaised;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MetricTrend extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'metric_statistic_id',
        'type',
        'detected_at',
        'start_date',
        'end_date',
        'baseline_value',
        'current_value',
        'deviation',
        'significance_score',
        'metadata',
        'acknowledged_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
        'baseline_value' => 'decimal:6',
        'current_value' => 'decimal:6',
        'deviation' => 'decimal:6',
        'significance_score' => 'decimal:4',
        'metadata' => 'array',
        'acknowledged_at' => 'datetime',
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

        // Broadcast anomalies to the iOS client the moment they're persisted.
        // Centralised here so both DetectMetricAnomaliesJob and DetectAnomaliesTask
        // (Task Pipeline) emit the event without duplicating dispatch code.
        static::created(function (MetricTrend $model) {
            if (! in_array($model->type, ['anomaly_high', 'anomaly_low'], true)) {
                return;
            }

            $userId = $model->metricStatistic?->user_id;
            if (! $userId) {
                return;
            }

            DB::afterCommit(function () use ($model, $userId) {
                event(AnomalyRaised::fromTrend($model, (string) $userId));
            });
        });
    }

    public function metricStatistic(): BelongsTo
    {
        return $this->belongsTo(MetricStatistic::class);
    }

    /**
     * Scope to get unacknowledged trends
     */
    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    /**
     * Scope to get acknowledged trends
     */
    public function scopeAcknowledged($query)
    {
        return $query->whereNotNull('acknowledged_at');
    }

    /**
     * Scope to filter by trend type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get anomalies only
     */
    public function scopeAnomalies($query)
    {
        return $query->whereIn('type', ['anomaly_high', 'anomaly_low']);
    }

    /**
     * Scope to get trends only (not anomalies)
     */
    public function scopeTrends($query)
    {
        return $query->whereNotIn('type', ['anomaly_high', 'anomaly_low']);
    }

    /**
     * Acknowledge this trend
     */
    public function acknowledge(): void
    {
        $this->update(['acknowledged_at' => now()]);
    }

    /**
     * Check if this is an anomaly
     */
    public function isAnomaly(): bool
    {
        return in_array($this->type, ['anomaly_high', 'anomaly_low']);
    }

    /**
     * Check if this is a trend
     */
    public function isTrend(): bool
    {
        return ! $this->isAnomaly();
    }

    /**
     * Get human-readable type label
     */
    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'anomaly_high' => 'Significantly High',
            'anomaly_low' => 'Significantly Low',
            'trend_up_weekly' => 'Trending Up (Weekly)',
            'trend_down_weekly' => 'Trending Down (Weekly)',
            'trend_up_monthly' => 'Trending Up (Monthly)',
            'trend_down_monthly' => 'Trending Down (Monthly)',
            'trend_up_quarterly' => 'Trending Up (Quarterly)',
            'trend_down_quarterly' => 'Trending Down (Quarterly)',
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }

    /**
     * Get direction for trend (up/down/neutral)
     */
    public function getDirection(): string
    {
        if (str_contains($this->type, 'up') || $this->type === 'anomaly_high') {
            return 'up';
        }

        if (str_contains($this->type, 'down') || $this->type === 'anomaly_low') {
            return 'down';
        }

        return 'neutral';
    }
}
