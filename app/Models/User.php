<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Boot the model and set the ID to a UUID on creation.
     */
    public static function booted()
    {
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
        });
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get the user's integrations
     */
    public function integrations()
    {
        return $this->hasMany(Integration::class);
    }

    public function integrationGroups()
    {
        return $this->hasMany(IntegrationGroup::class);
    }

    /**
     * Get the user's events
     */
    public function events()
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Get the user's metric statistics
     */
    public function metricStatistics()
    {
        return $this->hasMany(MetricStatistic::class);
    }

    /**
     * Get the user's sessions
     */
    public function sessions()
    {
        return $this->hasMany(Session::class)->orderBy('last_activity', 'desc');
    }

    /**
     * Check if debug logging is enabled for this user
     */
    public function hasDebugLoggingEnabled(): bool
    {
        $settings = $this->settings ?? [];

        // If user has explicitly set preference, use it
        if (isset($settings['debug_logging_enabled'])) {
            return (bool) $settings['debug_logging_enabled'];
        }

        // Otherwise fall back to environment variable (default true if not set)
        return config('logging.debug_logging_default', true);
    }

    /**
     * Enable debug logging for this user
     */
    public function enableDebugLogging(): void
    {
        $settings = $this->settings ?? [];
        $settings['debug_logging_enabled'] = true;
        $this->update(['settings' => $settings]);
    }

    /**
     * Disable debug logging for this user
     */
    public function disableDebugLogging(): void
    {
        $settings = $this->settings ?? [];
        $settings['debug_logging_enabled'] = false;
        $this->update(['settings' => $settings]);
    }

    /**
     * Get the first block of the user's UUID for log filenames
     */
    public function getUuidBlock(): string
    {
        return explode('-', $this->id)[0] ?? $this->id;
    }

    /**
     * Get the user's timezone preference
     */
    public function getTimezone(): string
    {
        $settings = $this->settings ?? [];

        return $settings['timezone'] ?? 'UTC';
    }

    /**
     * Set the user's timezone preference
     */
    public function setTimezone(string $timezone): void
    {
        $settings = $this->settings ?? [];
        $settings['timezone'] = $timezone;
        $this->update(['settings' => $settings]);
    }

    /**
     * Get notification preferences from settings
     */
    public function getNotificationPreferences(): array
    {
        $settings = $this->settings ?? [];

        return $settings['notifications'] ?? [
            'email_enabled' => [],
            'work_hours' => [
                'enabled' => false,
                'timezone' => 'UTC',
                'start' => '09:00',
                'end' => '17:00',
            ],
            'delayed_sending' => [
                'mode' => 'immediate',
                'digest_time' => '09:00',
            ],
        ];
    }

    /**
     * Check if email notifications are enabled for a specific notification type
     */
    public function hasEmailNotificationsEnabled(string $notificationType): bool
    {
        $preferences = $this->getNotificationPreferences();

        return $preferences['email_enabled'][$notificationType] ?? true;
    }

    /**
     * Enable email notifications for a specific notification type
     */
    public function enableEmailNotifications(string $notificationType): void
    {
        $settings = $this->settings ?? [];
        $notifications = $settings['notifications'] ?? [];
        $emailEnabled = $notifications['email_enabled'] ?? [];

        $emailEnabled[$notificationType] = true;
        $notifications['email_enabled'] = $emailEnabled;
        $settings['notifications'] = $notifications;

        $this->update(['settings' => $settings]);
    }

    /**
     * Disable email notifications for a specific notification type
     */
    public function disableEmailNotifications(string $notificationType): void
    {
        $settings = $this->settings ?? [];
        $notifications = $settings['notifications'] ?? [];
        $emailEnabled = $notifications['email_enabled'] ?? [];

        $emailEnabled[$notificationType] = false;
        $notifications['email_enabled'] = $emailEnabled;
        $settings['notifications'] = $notifications;

        $this->update(['settings' => $settings]);
    }

    /**
     * Check if push notifications are globally enabled for this user
     */
    public function hasPushNotificationsEnabled(): bool
    {
        $preferences = $this->getNotificationPreferences();

        return $preferences['push_enabled'] ?? true;
    }

    /**
     * Enable push notifications globally
     */
    public function enablePushNotifications(): void
    {
        $settings = $this->settings ?? [];
        $notifications = $settings['notifications'] ?? [];
        $notifications['push_enabled'] = true;
        $settings['notifications'] = $notifications;

        $this->update(['settings' => $settings]);
    }

    /**
     * Disable push notifications globally
     */
    public function disablePushNotifications(): void
    {
        $settings = $this->settings ?? [];
        $notifications = $settings['notifications'] ?? [];
        $notifications['push_enabled'] = false;
        $settings['notifications'] = $notifications;

        $this->update(['settings' => $settings]);
    }

    /**
     * Check if push notifications are enabled for a specific notification type
     */
    public function hasPushNotificationsEnabledForType(string $notificationType): bool
    {
        if (! $this->hasPushNotificationsEnabled()) {
            return false;
        }

        $preferences = $this->getNotificationPreferences();
        $pushTypes = $preferences['push_types'] ?? [];

        // Default to true if not explicitly set
        return $pushTypes[$notificationType] ?? true;
    }

    /**
     * Enable push notifications for a specific notification type
     */
    public function enablePushNotificationsForType(string $notificationType): void
    {
        $settings = $this->settings ?? [];
        $notifications = $settings['notifications'] ?? [];
        $pushTypes = $notifications['push_types'] ?? [];

        $pushTypes[$notificationType] = true;
        $notifications['push_types'] = $pushTypes;
        $settings['notifications'] = $notifications;

        $this->update(['settings' => $settings]);
    }

    /**
     * Disable push notifications for a specific notification type
     */
    public function disablePushNotificationsForType(string $notificationType): void
    {
        $settings = $this->settings ?? [];
        $notifications = $settings['notifications'] ?? [];
        $pushTypes = $notifications['push_types'] ?? [];

        $pushTypes[$notificationType] = false;
        $notifications['push_types'] = $pushTypes;
        $settings['notifications'] = $notifications;

        $this->update(['settings' => $settings]);
    }

    /**
     * Update notification preferences
     */
    public function updateNotificationPreferences(array $preferences): void
    {
        $settings = $this->settings ?? [];
        $notifications = $settings['notifications'] ?? [];

        $settings['notifications'] = array_merge($notifications, $preferences);

        $this->update(['settings' => $settings]);
    }

    /**
     * Check if user is currently in work hours
     */
    public function isInWorkHours(): bool
    {
        $preferences = $this->getNotificationPreferences();
        $workHours = $preferences['work_hours'];

        if (! $workHours['enabled']) {
            return true;
        }

        $timezone = $workHours['timezone'];
        $now = now()->timezone($timezone);
        $currentTime = $now->format('H:i');

        return $currentTime >= $workHours['start'] && $currentTime < $workHours['end'];
    }

    /**
     * Get the delayed sending mode
     */
    public function getDelayedSendingMode(): string
    {
        $preferences = $this->getNotificationPreferences();

        return $preferences['delayed_sending']['mode'] ?? 'immediate';
    }

    /**
     * Get the digest time for daily digest notifications
     */
    public function getDigestTime(): string
    {
        $preferences = $this->getNotificationPreferences();

        return $preferences['delayed_sending']['digest_time'] ?? '09:00';
    }

    /**
     * Get metric tracking preferences
     */
    public function getMetricTrackingPreferences(): array
    {
        $settings = $this->settings ?? [];

        return $settings['metric_tracking'] ?? [
            'disabled_metrics' => [],
        ];
    }

    /**
     * Check if a specific metric is disabled
     */
    public function isMetricTrackingDisabled(string $service, string $action, string $unit): bool
    {
        $preferences = $this->getMetricTrackingPreferences();
        $identifier = "{$service}.{$action}.{$unit}";

        return in_array($identifier, $preferences['disabled_metrics'] ?? []);
    }

    /**
     * Disable tracking for a specific metric
     */
    public function disableMetricTracking(string $service, string $action, string $unit): void
    {
        $settings = $this->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $disabledMetrics = $metricTracking['disabled_metrics'] ?? [];

        $identifier = "{$service}.{$action}.{$unit}";

        if (! in_array($identifier, $disabledMetrics)) {
            $disabledMetrics[] = $identifier;
            $metricTracking['disabled_metrics'] = $disabledMetrics;
            $settings['metric_tracking'] = $metricTracking;

            $this->update(['settings' => $settings]);
        }
    }

    /**
     * Enable tracking for a specific metric
     */
    public function enableMetricTracking(string $service, string $action, string $unit): void
    {
        $settings = $this->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $disabledMetrics = $metricTracking['disabled_metrics'] ?? [];

        $identifier = "{$service}.{$action}.{$unit}";

        $disabledMetrics = array_filter($disabledMetrics, fn ($metric) => $metric !== $identifier);
        $metricTracking['disabled_metrics'] = array_values($disabledMetrics);
        $settings['metric_tracking'] = $metricTracking;

        $this->update(['settings' => $settings]);
    }

    /**
     * Set anomaly detection mode override for a specific metric
     *
     * @param  string  $mode  One of: 'realtime', 'retrospective', 'disabled'
     */
    public function setAnomalyDetectionMode(string $service, string $action, string $unit, string $mode): void
    {
        $settings = $this->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $overrides = $metricTracking['anomaly_detection_mode_override'] ?? [];

        $identifier = "{$service}.{$action}.{$unit}";
        $overrides[$identifier] = $mode;

        $metricTracking['anomaly_detection_mode_override'] = $overrides;
        $settings['metric_tracking'] = $metricTracking;

        $this->update(['settings' => $settings]);
    }

    /**
     * Remove anomaly detection mode override for a specific metric
     */
    public function clearAnomalyDetectionModeOverride(string $service, string $action, string $unit): void
    {
        $settings = $this->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $overrides = $metricTracking['anomaly_detection_mode_override'] ?? [];

        $identifier = "{$service}.{$action}.{$unit}";
        unset($overrides[$identifier]);

        $metricTracking['anomaly_detection_mode_override'] = $overrides;
        $settings['metric_tracking'] = $metricTracking;

        $this->update(['settings' => $settings]);
    }

    /**
     * Get anomaly detection mode override for a specific metric
     */
    public function getAnomalyDetectionModeOverride(string $service, string $action, string $unit): ?string
    {
        $settings = $this->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $overrides = $metricTracking['anomaly_detection_mode_override'] ?? [];

        $identifier = "{$service}.{$action}.{$unit}";

        return $overrides[$identifier] ?? null;
    }

    /**
     * Check if auto-fetch is enabled for Fetch Discovery
     * Defaults to false (read-only mode)
     */
    public function getFetchDiscoveryAutoFetchEnabled(): bool
    {
        $settings = $this->settings ?? [];

        return $settings['fetch_discovery_auto_fetch'] ?? false;
    }

    /**
     * Set auto-fetch mode for Fetch Discovery
     */
    public function setFetchDiscoveryAutoFetchEnabled(bool $enabled): void
    {
        $settings = $this->settings ?? [];
        $settings['fetch_discovery_auto_fetch'] = $enabled;
        $this->update(['settings' => $settings]);
    }

    /**
     * Get excluded domains for Fetch Discovery
     * These domains will be filtered out during URL discovery
     */
    public function getFetchDiscoveryExcludedDomains(): array
    {
        $settings = $this->settings ?? [];

        return $settings['fetch_discovery_excluded_domains'] ?? [];
    }

    /**
     * Set excluded domains for Fetch Discovery
     */
    public function setFetchDiscoveryExcludedDomains(array $domains): void
    {
        $settings = $this->settings ?? [];
        // Normalize domains (remove protocol, www, trailing slash)
        $normalizedDomains = array_map(function ($domain) {
            return $this->normalizeDomain($domain);
        }, $domains);
        $settings['fetch_discovery_excluded_domains'] = array_values(array_unique($normalizedDomains));
        $this->update(['settings' => $settings]);
    }

    /**
     * Add domain to Fetch Discovery exclusion list
     */
    public function addFetchDiscoveryExcludedDomain(string $domain): void
    {
        $excluded = $this->getFetchDiscoveryExcludedDomains();
        $domain = $this->normalizeDomain($domain);
        if (! in_array($domain, $excluded)) {
            $excluded[] = $domain;
            $this->setFetchDiscoveryExcludedDomains($excluded);
        }
    }

    /**
     * Remove domain from Fetch Discovery exclusion list
     */
    public function removeFetchDiscoveryExcludedDomain(string $domain): void
    {
        $excluded = $this->getFetchDiscoveryExcludedDomains();
        $domain = $this->normalizeDomain($domain);
        $excluded = array_filter($excluded, fn ($d) => $d !== $domain);
        $this->setFetchDiscoveryExcludedDomains(array_values($excluded));
    }

    /**
     * Check if domain is excluded from Fetch Discovery
     */
    public function isFetchDiscoveryDomainExcluded(string $domain): bool
    {
        $excluded = $this->getFetchDiscoveryExcludedDomains();
        $domain = $this->normalizeDomain($domain);

        return in_array($domain, $excluded);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }

    /**
     * Normalize domain for comparison
     */
    private function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        $domain = rtrim($domain, '/');

        return strtolower($domain);
    }
}
