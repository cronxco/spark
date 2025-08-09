<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Integration extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'integrations';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'service',
        'name',
        'account_id',
        'access_token',
        'refresh_token',
        'expiry',
        'refresh_expiry',
        'configuration',
        'update_frequency_minutes',
        'last_triggered_at',
        'last_successful_update_at',
    ];

    protected $casts = [
        'expiry' => 'datetime',
        'refresh_expiry' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'configuration' => 'array',
        'last_triggered_at' => 'datetime',
        'last_successful_update_at' => 'datetime',
    ];

    protected static function booted()
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

    /**
     * Check if this integration needs to be updated based on its frequency
     */
    public function needsUpdate(): bool
    {
        // If never updated, it needs updating
        if (!$this->last_successful_update_at) {
            return true;
        }

        // Check if enough time has passed since last successful update
        $nextUpdateTime = $this->last_successful_update_at->addMinutes($this->update_frequency_minutes);
        
        return now()->isAfter($nextUpdateTime);
    }

    /**
     * Get the next scheduled update time
     */
    public function getNextUpdateTime(): ?Carbon
    {
        if (!$this->last_successful_update_at) {
            return null;
        }

        return $this->last_successful_update_at->addMinutes($this->update_frequency_minutes);
    }

    /**
     * Mark the integration as triggered
     */
    public function markAsTriggered(): void
    {
        $this->update(['last_triggered_at' => now()]);
    }

    /**
     * Mark the integration as successfully updated
     */
    public function markAsSuccessfullyUpdated(): void
    {
        $this->update([
            'last_successful_update_at' => now(),
            'last_triggered_at' => now(),
        ]);
    }

    /**
     * Get integrations that need updating
     */
    public static function scopeNeedsUpdate($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_successful_update_at')
              ->orWhereRaw('last_successful_update_at + INTERVAL \'1 minute\' * update_frequency_minutes < NOW()');
        });
    }

    /**
     * Get OAuth integrations that need updating
     */
    public static function scopeOAuthNeedsUpdate($query)
    {
        return $query->whereIn('service', \App\Integrations\PluginRegistry::getOAuthPlugins()->keys())
                    ->needsUpdate();
    }
} 