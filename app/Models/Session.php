<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jenssegers\Agent\Agent;

class Session extends Model
{
    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     */
    protected string $table = 'sessions';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'last_activity' => 'datetime',
    ];

    /**
     * Get the user that owns the session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the device information for the session.
     */
    public function getDeviceAttribute(): array
    {
        $agent = new Agent;
        $agent->setUserAgent($this->user_agent);

        return [
            'platform' => $this->getPlatform($agent),
            'browser' => $agent->browser(),
            'is_desktop' => $agent->isDesktop(),
            'is_mobile' => $agent->isMobile(),
            'is_tablet' => $agent->isTablet(),
            'device_name' => $this->getDeviceName($agent),
        ];
    }

    /**
     * Get a human-readable device name.
     */
    public function getDeviceNameAttribute(): string
    {
        $device = $this->device;

        if ($device['is_mobile']) {
            return $device['platform'].' Mobile - '.$device['browser'];
        }

        if ($device['is_tablet']) {
            return $device['platform'].' Tablet - '.$device['browser'];
        }

        return $device['platform'].' Desktop - '.$device['browser'];
    }

    /**
     * Check if this is the current session.
     */
    public function getIsCurrentAttribute(): bool
    {
        return $this->id === session()->getId();
    }

    /**
     * Get the last activity in human readable format.
     */
    public function getLastActivityHumanAttribute(): string
    {
        return $this->last_activity->diffForHumans();
    }

    /**
     * Get the location information (if available).
     */
    public function getLocationAttribute(): ?string
    {
        // You could integrate with a GeoIP service here
        // For now, we'll just return the IP address
        return $this->ip_address;
    }

    /**
     * Scope to get sessions for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get active sessions (recent activity).
     */
    public function scopeActive($query, $minutes = 60)
    {
        return $query->where('last_activity', '>=', now()->subMinutes($minutes)->timestamp);
    }

    /**
     * Invalidate this session.
     */
    public function invalidate(): bool
    {
        return $this->delete();
    }

    /**
     * Get the platform name from the user agent.
     */
    protected function getPlatform(Agent $agent): string
    {
        if ($agent->isAndroidOS()) {
            return 'Android';
        }

        if ($agent->isIOS()) {
            return 'iOS';
        }

        if ($agent->isMac()) {
            return 'macOS';
        }

        if ($agent->isWindows()) {
            return 'Windows';
        }

        if ($agent->isLinux()) {
            return 'Linux';
        }

        return $agent->platform() ?: 'Unknown';
    }

    /**
     * Get a more specific device name.
     */
    protected function getDeviceName(Agent $agent): string
    {
        $platform = $this->getPlatform($agent);
        $browser = $agent->browser();

        if ($agent->isMobile()) {
            return "{$platform} Mobile";
        }

        if ($agent->isTablet()) {
            return "{$platform} Tablet";
        }

        return "{$platform} Desktop";
    }
}
