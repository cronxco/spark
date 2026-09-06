<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class IntegrationGroup extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

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

    /**
     * `access_token`, `refresh_token` and `webhook_secret` are encrypted at the
     * application boundary per ADR 0018, so a database or backup disclosure does
     * not yield reusable provider credentials. All three are `text` columns and
     * appear in no `where()` clause anywhere in the application, so the cast
     * needs no schema change and breaks no lookup. Run
     * `integrations:encrypt-credentials` to convert existing plaintext rows.
     *
     * `auth_metadata` stays a plain array: it is `jsonb` and is read through SQL
     * JSON paths (e.g. `auth_metadata->gocardless_reference`), which whole-column
     * encryption would break. The API keys some plugins keep inside it are
     * tracked separately as INT-01 phase 2.
     */
    protected $casts = [
        'expiry' => 'datetime',
        'refresh_expiry' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'auth_metadata' => 'array',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('changelog')
            ->logFillable()
            // logFillable() reads attributes through their casts, so without
            // logExcept() the activity log would record decrypted credentials in
            // its diffs whenever any other fillable attribute changed.
            // dontLogIfAttributesChangedOnly() does not redact — it only skips
            // the log when nothing else changed.
            ->logExcept(['access_token', 'refresh_token', 'webhook_secret', 'auth_metadata'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'access_token', 'refresh_token', 'expiry']);
    }

    /**
     * Get the first block of the group's UUID for log filenames
     */
    public function getUuidBlock(): string
    {
        return explode('-', $this->id)[0] ?? $this->id;
    }
}
