<?php

namespace App\Models;

use App\Models\Integration;
use App\Models\User;
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
        'integration_id',
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
            
            // Automatically set user_id from integration if not provided
            if (empty($model->user_id) && $model->integration_id) {
                $integration = Integration::find($model->integration_id);
                if ($integration) {
                    $model->user_id = $integration->user_id;
                }
            }
        });
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
