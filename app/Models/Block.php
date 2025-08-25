<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Block extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $table = 'blocks';
    protected $keyType = 'string';

    protected $fillable = [
        'event_id',
        'time',
        'title',
        'content',
        'url',
        'media_url',
        'value',
        'value_multiplier',
        'value_unit',
        'embeddings',
    ];

    protected $casts = [
        'time' => 'datetime',
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

    public function event()
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }
}
