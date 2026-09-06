<?php

namespace App\Notifications;

/**
 * The notification types Spark actually sends, and how each is presented.
 *
 * One source of truth for four consumers that had drifted apart: the mobile
 * settings endpoints, the web settings page, ApnsChannel's category mapping and
 * the iOS client's UNNotificationCategory registrations.
 *
 * The mobile API previously invented five categories — anomaly, digest,
 * integration_failed, new_bookmark, calendar_event — of which only
 * integration_failed corresponded to anything that is ever sent. Meanwhile
 * SparkNotification::via() gates on hasPushNotificationsEnabledForType(), which
 * is keyed by the real type string, so four of the five toggles controlled
 * nothing and three real types had no mobile toggle at all.
 *
 * Every key below is a string returned by a SparkNotification subclass's
 * getNotificationType(). Adding one here is what makes it configurable and
 * gives it action buttons; nothing else needs changing.
 */
class NotificationCatalogue
{
    /**
     * @var array<string, array{label: string, description: string, apns_category: ?string, configurable: bool}>
     */
    private const TYPES = [
        'integration_completed' => [
            'label' => 'Integration Completed',
            'description' => 'Notify when an integration finishes syncing successfully',
            'apns_category' => 'INTEGRATION_STATUS',
            'configurable' => true,
        ],
        'integration_failed' => [
            'label' => 'Integration Failed',
            'description' => 'Notify when an integration sync fails (always sent immediately)',
            'apns_category' => 'INTEGRATION_STATUS',
            'configurable' => true,
        ],
        'integration_authentication_failed' => [
            'label' => 'Authentication Required',
            'description' => 'Notify when an integration needs re-authorization (always sent immediately)',
            'apns_category' => 'INTEGRATION_ATTENTION',
            'configurable' => true,
        ],
        'cookie_expiry_warning' => [
            'label' => 'Saved Login Expiring',
            'description' => 'Notify when a saved website login is about to expire and needs refreshing',
            'apns_category' => 'INTEGRATION_ATTENTION',
            'configurable' => true,
        ],
        'fetch_multiple_failures' => [
            'label' => 'Repeated Fetch Failures',
            'description' => 'Notify when a tracked page has failed to fetch several times in a row',
            'apns_category' => 'INTEGRATION_STATUS',
            'configurable' => true,
        ],
        'fetch_content_changed' => [
            'label' => 'Tracked Page Changed',
            'description' => 'Notify when a tracked page\'s content changes',
            'apns_category' => 'INTEGRATION_STATUS',
            'configurable' => true,
        ],
        'migration_completed' => [
            'label' => 'Historical Data Import Complete',
            'description' => 'Notify when historical data migration finishes successfully',
            'apns_category' => 'INTEGRATION_STATUS',
            'configurable' => true,
        ],
        'migration_failed' => [
            'label' => 'Historical Data Import Failed',
            'description' => 'Notify when historical data migration fails (always sent immediately)',
            'apns_category' => 'INTEGRATION_STATUS',
            'configurable' => true,
        ],
        'data_export_ready' => [
            'label' => 'Data Export Ready',
            'description' => 'Notify when your data export is ready for download',
            'apns_category' => 'SYSTEM',
            'configurable' => true,
        ],
        'system_maintenance' => [
            'label' => 'System Maintenance',
            'description' => 'Notify about system maintenance and updates (always sent immediately)',
            'apns_category' => 'SYSTEM',
            'configurable' => true,
        ],
        // Sent only by SendTestPushNotification, so it is not something to
        // offer as a preference — but it still needs a category so the test
        // push exercises the same path a real one takes.
        'test_push' => [
            'label' => 'Test Notification',
            'description' => 'Delivery test sent on request',
            'apns_category' => 'SYSTEM',
            'configurable' => false,
        ],
    ];

    /**
     * Every known type, in presentation order.
     *
     * @return array<string, array{label: string, description: string, apns_category: ?string, configurable: bool}>
     */
    public static function all(): array
    {
        return self::TYPES;
    }

    /**
     * The types a user may switch on and off.
     *
     * @return array<string, array{label: string, description: string, apns_category: ?string, configurable: bool}>
     */
    public static function configurable(): array
    {
        return array_filter(self::TYPES, fn (array $type) => $type['configurable']);
    }

    /**
     * Keys of the types a user may switch on and off.
     *
     * @return array<int, string>
     */
    public static function configurableTypes(): array
    {
        return array_keys(self::configurable());
    }

    /**
     * Notification type => UNNotificationCategory identifier.
     *
     * The category identifier selects which action buttons the client shows, so
     * it must be one the client registered, and matching is case-sensitive.
     *
     * @return array<string, string>
     */
    public static function apnsCategories(): array
    {
        return array_filter(array_map(
            fn (array $type) => $type['apns_category'],
            self::TYPES,
        ));
    }

    /**
     * The distinct categories the iOS client must register.
     *
     * @return array<int, string>
     */
    public static function apnsCategoryIdentifiers(): array
    {
        return array_values(array_unique(array_values(self::apnsCategories())));
    }
}
