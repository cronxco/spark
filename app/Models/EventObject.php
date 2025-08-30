<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Tags\HasTags;

class EventObject extends Model
{
    use HasFactory, HasTags, SoftDeletes;

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
