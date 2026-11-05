<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveActivityToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'device_id',
        'activity_type',
        'activity_id',
        'push_token',
        'starts_at',
        'ends_at',
        'last_pushed_at',
    ];

    protected $hidden = ['push_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PushSubscription::class, 'device_id');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'push_token' => 'encrypted',
        ];
    }
}
