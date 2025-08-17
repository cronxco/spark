<?php

namespace App\Models;

use App\Integrations\PluginRegistry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Integration extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $table = 'integrations';
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'integration_group_id',
        'service',
        'name',
        'instance_type',
        'account_id',
        'configuration',
        'update_frequency_minutes',
        'last_triggered_at',
        'last_successful_update_at',
        'migration_batch_id',
    ];

    protected $casts = [
        // tokens now live on IntegrationGroup
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'configuration' => 'array',
        'last_triggered_at' => 'datetime',
        'last_successful_update_at' => 'datetime',
    ];

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
        return $query->whereIn('service', PluginRegistry::getOAuthPlugins()->keys())
            ->needsUpdate();
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });

        static::deleted(function (Integration $integration): void {
            if (! $integration->integration_group_id) {
                return;
            }

            $group = $integration->group;

            if (! $group) {
                return;
            }

            if ($group->integrations()->exists()) {
                return;
            }

            $group->delete();
        });

        static::forceDeleted(function (Integration $integration): void {
            if (! $integration->integration_group_id) {
                return;
            }

            $group = $integration->group;

            if (! $group) {
                return;
            }

            if ($group->integrations()->exists()) {
                return;
            }

            $group->delete();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function group()
    {
        return $this->belongsTo(IntegrationGroup::class, 'integration_group_id');
    }

    /**
     * Check if this integration needs to be updated based on its frequency
     */
    public function needsUpdate(): bool
    {
        // If never updated, it needs updating
        if (! $this->last_successful_update_at) {
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
        if (! $this->last_successful_update_at) {
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
     * Mark the integration as failed
     */
    public function markAsFailed(): void
    {
        // Clear the triggered state so it can be retried
        // Don't update last_successful_update_at since the update failed
        $this->update(['last_triggered_at' => null]);
    }

    /**
     * Check if this integration is currently being processed
     */
    public function isProcessing(): bool
    {
        if (! $this->last_triggered_at) {
            return false;
        }

        // Bound the "processing" window to avoid indefinite lockout if a job fails/crashes.
        // Use update_frequency_minutes where available, clamped to [5, 30] minutes.
        $windowMinutes = (int) ($this->update_frequency_minutes ?? 15);
        $windowMinutes = max(5, min(30, $windowMinutes));

        $triggerIsRecent = $this->last_triggered_at->gt(now()->subMinutes($windowMinutes));
        $triggerAfterLastSuccess = ! $this->last_successful_update_at
            || $this->last_triggered_at->gt($this->last_successful_update_at);

        return $triggerIsRecent && $triggerAfterLastSuccess;
    }
}
