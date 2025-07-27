<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EventObject extends Model
{
    use HasFactory;

    protected $table = 'objects';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'time',
        'integration_id',
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
        return $this->belongsTo(Integration::class);
    }
}
