<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class IntegrationGroup extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $table = 'integration_groups';

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'service',
        'account_id',
        'webhook_secret',
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

    /**
     * Get all events from integrations in this group
     */
    public function getRelatedEvents()
    {
        return Event::whereIn('integration_id', $this->integrations()->pluck('id'))
            ->with(['blocks', 'actor', 'target'])
            ->get();
    }

    /**
     * Get all blocks from events in this group
     */
    public function getRelatedBlocks()
    {
        $eventIds = $this->getRelatedEvents()->pluck('id');

        return Block::whereIn('event_id', $eventIds)->get();
    }

    /**
     * Get all objects used by events in this group
     */
    public function getRelatedObjects()
    {
        $events = $this->getRelatedEvents();
        $actorIds = $events->pluck('actor_id')->filter();
        $targetIds = $events->pluck('target_id')->filter();

        return EventObject::whereIn('id', $actorIds->merge($targetIds))->get();
    }

    /**
     * Get deletion summary for this group
     */
    public function getDeletionSummary(): array
    {

        return [
            'integrations' => $this->integrations()->count(),
            'events' => $this->getRelatedEvents()->count(),
            'blocks' => $this->getRelatedBlocks()->count(),
            'objects' => $this->getRelatedObjects()->count(),
            'service_name' => $this->service,
            'account_id' => $this->account_id,
        ];
    }
}
