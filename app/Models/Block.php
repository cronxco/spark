<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Block extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Only record update events via LogsActivity trait.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['updated'];

    public $incrementing = false;

    protected $table = 'blocks';

    protected $keyType = 'string';

    protected $fillable = [
        'event_id',
        'block_type',
        'time',
        'title',
        'metadata',
        'url',
        'media_url',
        'value',
        'value_multiplier',
        'value_unit',
        'embeddings',
    ];

    protected $casts = [
        'time' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });

        static::deleted(function ($model): void {
            activity('changelog')
                ->performedOn($model)
                ->event('deleted')
                ->log('deleted');
        });

        static::restored(function ($model): void {
            activity('changelog')
                ->performedOn($model)
                ->event('restored')
                ->log('restored');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('changelog')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    public function event()
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class)->withTrashed();
    }

    /**
     * Get the formatted value considering the multiplier
     */
    public function getFormattedValueAttribute()
    {
        if ($this->value === null || $this->value_multiplier === null) {
            return $this->value;
        }

        if ($this->value_multiplier === 1) {
            return $this->value;
        }

        return $this->value / $this->value_multiplier;
    }
}
