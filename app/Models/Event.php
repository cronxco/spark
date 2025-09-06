<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Tags\HasTags;

class Event extends Model
{
    use HasFactory, HasTags, LogsActivity, SoftDeletes;

    /**
     * Only record update events via LogsActivity trait
     * to avoid duplicating data on create/delete.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['updated'];

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

    /**
     * Get related objects through direct relationships (actor/target)
     * This maintains compatibility with the original simple design
     */
    public function objects()
    {
        return new class($this)
        {
            private $event;

            public function __construct($event)
            {
                $this->event = $event;
            }

            public function syncWithoutDetaching($relationships)
            {
                // For backward compatibility, just return true
                // The original design didn't need complex object relationships
                return true;
            }

            public function get()
            {
                $objects = collect();

                // Return actor and target objects
                if ($this->event->actor) {
                    $this->event->actor->pivot = (object) ['role' => 'actor'];
                    $objects->push($this->event->actor);
                }

                if ($this->event->target) {
                    $this->event->target->pivot = (object) ['role' => 'target'];
                    $objects->push($this->event->target);
                }

                return $objects;
            }
        };
    }
}
