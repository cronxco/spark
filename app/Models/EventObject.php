<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Tags\HasTags;

class EventObject extends Model
{
    use HasFactory, HasTags, LogsActivity, SoftDeletes;

    /**
     * Only record update events via LogsActivity trait.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['updated'];

    public $incrementing = false;

    protected $table = 'objects';

    protected $keyType = 'string';

    protected $fillable = [
        'time',
        'user_id',
        'concept',
        'type',
        'title',
        'content',
        'metadata',
        'url',
        'media_url',
        'embeddings',
    ];

    protected $casts = [
        'time' => 'datetime',
        'metadata' => 'array',
        'embeddings' => 'array', // You may need a custom cast for vector fields
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

            // no-op: user_id must be provided by callers now
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

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function actorEvents()
    {
        return $this->hasMany(Event::class, 'actor_id')->withTrashed();
    }

    public function targetEvents()
    {
        return $this->hasMany(Event::class, 'target_id')->withTrashed();
    }

    public function events()
    {
        return $this->actorEvents()->union($this->targetEvents());
    }
}
