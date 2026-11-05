<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A learned "usual" for one metric over one window and day type. The
 * whole-metric, 90-day, all-days baseline is also mirrored onto the
 * MetricStatistic row that older readers use.
 */
class MetricBaseline extends Model
{
    use HasFactory;

    public const ALGORITHM_VERSION = 1;

    public const DAY_TYPE_ALL = 'all';

    public const DAY_TYPE_WEEKDAY = 'weekday';

    public const DAY_TYPE_WEEKEND = 'weekend';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'metric_statistic_id',
        'subject_id',
        'window_days',
        'day_type',
        'method',
        'centre',
        'spread',
        'lower',
        'upper',
        'sample_count',
        'effect_significance',
        'algorithm_version',
        'computed_at',
    ];

    protected $casts = [
        'window_days' => 'integer',
        'centre' => 'float',
        'spread' => 'float',
        'lower' => 'float',
        'upper' => 'float',
        'sample_count' => 'integer',
        'effect_significance' => 'float',
        'algorithm_version' => 'integer',
        'computed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function metricStatistic(): BelongsTo
    {
        return $this->belongsTo(MetricStatistic::class);
    }

    public static function dayTypeForIsoWeekday(int $isoWeekday): string
    {
        return $isoWeekday >= 6 ? self::DAY_TYPE_WEEKEND : self::DAY_TYPE_WEEKDAY;
    }
}
