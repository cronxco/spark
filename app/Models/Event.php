<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Tags\HasTags;

class Event extends Model
{
    use HasFactory, HasTags, SoftDeletes;

    public $incrementing = false;

    protected $table = 'events';

    protected $keyType = 'string';

    protected $fillable = [
        'source_id',
        'time',
        'integration_id',
        'actor_id',
        'actor_metadata',
        'service',
        'domain',
        'action',
        'value',
        'value_multiplier',
        'value_unit',
        'event_metadata',
        'target_id',
        'target_metadata',
        'embeddings',
    ];

    protected $casts = [
        'time' => 'datetime',
        'actor_metadata' => 'array',
        'event_metadata' => 'array',
        'target_metadata' => 'array',
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
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class)->withTrashed();
    }

    public function actor()
    {
        return $this->belongsTo(EventObject::class, 'actor_id')->withTrashed();
    }

    public function target()
    {
        return $this->belongsTo(EventObject::class, 'target_id')->withTrashed();
    }

    public function blocks()
    {
        return $this->hasMany(Block::class)->withTrashed();
    }

    /**
     * Get the formatted value considering the multiplier
     */
    public function getFormattedValueAttribute()
    {
        if ($this->value === null || $this->value_multiplier === null) {
            return $this->value;
        }

        if ($this->value_multiplier === 1 || $this->value_multiplier === 0) {
            return $this->value;
        }

        return $this->value / $this->value_multiplier;
    }
}
