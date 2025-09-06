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
     * Note: This scope is approximate since update_frequency_minutes is now in configurationa
     * For exact filtering, use the needsUpdate() method on individual models
     */
    public static function scopeNeedsUpdate($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_successful_update_at')
                ->orWhereRaw('last_successful_update_at + INTERVAL \'1 minute\' * 15 < NOW()');
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

    /**
     * Get the update frequency in minutes from configuration
     */
    public function getUpdateFrequencyMinutes(): int
    {
        $config = $this->configuration ?? [];

        return $config['update_frequency_minutes'] ?? 15;
    }

    /**
     * Whether this instance is a task instance
     */
    public function isTaskInstance(): bool
    {
        return ($this->instance_type === 'task') || ($this->service === 'task');
    }

    /**
     * Whether this instance is paused
     */
    public function isPaused(): bool
    {
        $config = $this->configuration ?? [];

        return (bool) ($config['paused'] ?? false);
    }

    /**
     * Whether this instance should use schedule times instead of frequency
     */
    public function useSchedule(): bool
    {
        $config = $this->configuration ?? [];

        return (bool) ($config['use_schedule'] ?? false);
    }

    /**
     * Get configured schedule times as HH:mm strings
     *
     * @return array<int, string>
     */
    public function getScheduleTimes(): array
    {
        $config = $this->configuration ?? [];
        $times = $config['schedule_times'] ?? [];
        if (! is_array($times)) {
            return [];
        }

        // Filter to valid HH:mm entries
        return array_values(array_filter($times, static function ($t) {
            if (! is_string($t)) {
                return false;
            }

            return (bool) preg_match('/^\d{2}:\d{2}$/', $t);
        }));
    }

    /**
     * Get schedule timezone (IANA), default to UTC
     */
    public function getScheduleTimezone(): string
    {
        $config = $this->configuration ?? [];
        $tz = $config['schedule_timezone'] ?? config('app.timezone', 'UTC');

        return is_string($tz) && $tz !== '' ? $tz : (config('app.timezone', 'UTC'));
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
     * Check if this integration needs to be updated (schedule overrides frequency)
     */
    public function needsUpdate(): bool
    {
        return $this->isDue();
    }

    /**
     * Get the next scheduled update time
     */
    public function getNextUpdateTime(): ?Carbon
    {
        if ($this->isPaused()) {
            return null;
        }

        if ($this->useSchedule()) {
            return $this->getNextScheduledRun();
        }

        if (! $this->last_successful_update_at) {
            return null;
        }

        return $this->last_successful_update_at->copy()->addMinutes($this->getUpdateFrequencyMinutes());
    }

    /**
     * Compute the next scheduled run time in UTC if using schedule
     */
    public function getNextScheduledRun(?Carbon $now = null): ?Carbon
    {
        if (! $this->useSchedule()) {
            return null;
        }

        $times = $this->getScheduleTimes();
        if (empty($times)) {
            return null;
        }

        $tz = $this->getScheduleTimezone();
        $nowTz = $now ? (clone $now)->setTimezone($tz) : Carbon::now($tz);

        // Build times for today in tz
        $candidates = [];
        foreach ($times as $t) {
            [$h, $m] = [substr($t, 0, 2), substr($t, 3, 2)];
            $candidates[] = Carbon::createFromTime((int) $h, (int) $m, 0, $tz)
                ->setDate($nowTz->year, $nowTz->month, $nowTz->day);
        }

        usort($candidates, static function (Carbon $a, Carbon $b) {
            return $a->getTimestamp() <=> $b->getTimestamp();
        });

        foreach ($candidates as $candidate) {
            if ($candidate->greaterThan($nowTz)) {
                return $candidate->clone()->setTimezone('UTC');
            }
        }

        // Otherwise, first time tomorrow
        $first = $candidates[0]->clone()->addDay();

        return $first->setTimezone('UTC');
    }

    /**
     * Compute the first scheduled run that occurs strictly after the given moment
     */
    public function getNextScheduledRunAfter(Carbon $after): ?Carbon
    {
        if (! $this->useSchedule()) {
            return null;
        }

        $times = $this->getScheduleTimes();
        if (empty($times)) {
            return null;
        }

        $tz = $this->getScheduleTimezone();
        $cursorTz = $after->copy()->setTimezone($tz);

        // Build candidates for today and tomorrow relative to the "after" timestamp
        $candidates = [];
        foreach ($times as $t) {
            [$h, $m] = [substr($t, 0, 2), substr($t, 3, 2)];
            $today = Carbon::createFromTime((int) $h, (int) $m, 0, $tz)
                ->setDate($cursorTz->year, $cursorTz->month, $cursorTz->day);
            $tomorrow = $today->copy()->addDay();
            $candidates[] = $today;
            $candidates[] = $tomorrow;
        }

        usort($candidates, static function (Carbon $a, Carbon $b) {
            return $a->getTimestamp() <=> $b->getTimestamp();
        });

        foreach ($candidates as $candidate) {
            if ($candidate->greaterThan($cursorTz)) {
                return $candidate->clone()->setTimezone('UTC');
            }
        }

        return null;
    }

    /**
     * Human-friendly schedule summary (e.g., "4× daily at 04:10, 10:10, 16:10, 22:10 (Europe/London)")
     */
    public function getScheduleSummary(): ?string
    {
        if (! $this->useSchedule()) {
            return null;
        }

        $times = $this->getScheduleTimes();
        if (empty($times)) {
            return null;
        }

        $tz = $this->getScheduleTimezone();
        $count = count($times);
        $timesList = implode(', ', $times);

        return $count . '× daily at ' . $timesList . ' (' . $tz . ')';
    }

    /**
     * Whether the integration is due to run now
     */
    public function isDue(?Carbon $now = null): bool
    {
        if ($this->isPaused()) {
            return false;
        }

        $nowUtc = $now ? (clone $now)->setTimezone('UTC') : Carbon::now('UTC');

        if ($this->useSchedule()) {
            if (! $this->last_successful_update_at) {
                return true;
            }

            // Determine the next run strictly after last success, and compare to now
            $nextAfterSuccess = $this->getNextScheduledRunAfter($this->last_successful_update_at->copy());

            return $nextAfterSuccess !== null && $nowUtc->greaterThanOrEqualTo($nextAfterSuccess);
        }

        // Frequency-based fallback
        if (! $this->last_successful_update_at) {
            return true;
        }

        $nextUpdateTime = $this->last_successful_update_at->copy()->addMinutes($this->getUpdateFrequencyMinutes());

        return $nowUtc->greaterThanOrEqualTo($nextUpdateTime);
    }

    /**
     * Simple throttle guard to avoid immediate re-triggering
     */
    public function shouldThrottle(): bool
    {
        if (! $this->last_triggered_at) {
            return false;
        }

        $windowMinutes = (int) $this->getUpdateFrequencyMinutes();
        $windowMinutes = max(5, min(30, $windowMinutes));

        return $this->last_triggered_at->addMinutes($windowMinutes)->isFuture();
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
        $windowMinutes = (int) $this->getUpdateFrequencyMinutes();
        $windowMinutes = max(5, min(30, $windowMinutes));

        $triggerIsRecent = $this->last_triggered_at->gt(now()->subMinutes($windowMinutes));
        $triggerAfterLastSuccess = ! $this->last_successful_update_at
            || $this->last_triggered_at->gt($this->last_successful_update_at);

        return $triggerIsRecent && $triggerAfterLastSuccess;
    }
}
