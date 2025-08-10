<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class IntegrationGroup extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'integration_groups';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'service',
        'account_id',
        'access_token',
        'refresh_token',
        'expiry',
        'refresh_expiry',
        'auth_metadata',
    ];

    protected $casts = [
        'expiry' => 'datetime',
        'refresh_expiry' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'auth_metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function integrations()
    {
        return $this->hasMany(Integration::class, 'integration_group_id');
    }
}


