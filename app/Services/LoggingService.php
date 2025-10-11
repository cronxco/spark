<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class LoggingService
{
    /**
     * Get the daily log channel for a user
     */
    public static function getUserLogChannel(User $user): string
    {
        $uuidBlock = $user->getUuidBlock();
        $channelName = "user_{$uuidBlock}";

        if (! config('logging.channels.' . $channelName)) {
            config([
                'logging.channels.' . $channelName => [
                    'driver' => 'daily',
                    'path' => storage_path("logs/user_{$uuidBlock}.log"),
                    'level' => 'debug',
                    'days' => 14,
                    'replace_placeholders' => true,
                ],
            ]);
        }

        return $channelName;
    }

    /**
     * Get the daily log channel for an integration group
     */
    public static function getGroupLogChannel(IntegrationGroup $group): string
    {
        $service = $group->service;
        $uuidBlock = $group->getUuidBlock();
        $channelName = "group_{$service}_{$uuidBlock}";

        if (! config('logging.channels.' . $channelName)) {
            config([
                'logging.channels.' . $channelName => [
                    'driver' => 'daily',
                    'path' => storage_path("logs/group_{$service}_{$uuidBlock}.log"),
                    'level' => 'debug',
                    'days' => 14,
                    'replace_placeholders' => true,
                ],
            ]);
        }

        return $channelName;
    }

    /**
     * Get the daily log channel for an integration instance
     */
    public static function getIntegrationLogChannel(Integration $integration): string
    {
        $service = $integration->service;
        $instanceType = $integration->instance_type ?? 'default';
        $uuidBlock = $integration->getUuidBlock();
        $channelName = "integration_{$service}_{$instanceType}_{$uuidBlock}";

        if (! config('logging.channels.' . $channelName)) {
            config([
                'logging.channels.' . $channelName => [
                    'driver' => 'daily',
                    'path' => storage_path("logs/integration_{$service}_{$instanceType}_{$uuidBlock}.log"),
                    'level' => 'debug',
                    'days' => 7,
                    'replace_placeholders' => true,
                ],
            ]);
        }

        return $channelName;
    }

    /**
     * Log a message to user channel
     */
    public static function logToUser(User $user, string $level, string $message, array $context = []): void
    {
        $channel = static::getUserLogChannel($user);
        Log::channel($channel)->log($level, $message, $context);
    }

    /**
     * Log a message to integration group channel
     */
    public static function logToGroup(IntegrationGroup $group, string $level, string $message, array $context = []): void
    {
        $channel = static::getGroupLogChannel($group);
        Log::channel($channel)->log($level, $message, $context);
    }

    /**
     * Log a message to integration instance channel (only if debug enabled)
     */
    public static function logToIntegration(Integration $integration, string $level, string $message, array $context = []): void
    {
        $user = $integration->user;

        // Only log debug messages if user has debug logging enabled
        if ($level === 'debug' && ! $user->hasDebugLoggingEnabled()) {
            return;
        }

        $channel = static::getIntegrationLogChannel($integration);
        Log::channel($channel)->log($level, $message, $context);
    }

    /**
     * Log a message hierarchically: instance → group → user
     * Debug logs only go to instance (if enabled), info/warning/error cascade up
     */
    public static function logHierarchical(
        Integration $integration,
        string $level,
        string $message,
        array $context = []
    ): void {
        $user = $integration->user;
        $group = $integration->group;

        // Log to integration instance (respects debug logging setting)
        static::logToIntegration($integration, $level, $message, $context);

        // Cascade info/warning/error up to group and user
        if (in_array($level, ['info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])) {
            if ($group) {
                static::logToGroup($group, $level, $message, $context);
            }

            if ($user) {
                static::logToUser($user, $level, $message, $context);
            }
        }
    }

    /**
     * Get log file path for a user on a specific date
     * Laravel's daily driver appends the date with a hyphen: user_{uuid}-{date}.log
     */
    public static function getUserLogPath(User $user, ?string $date = null): string
    {
        $date = $date ?? now()->format('Y-m-d');
        $uuidBlock = $user->getUuidBlock();

        return storage_path("logs/user_{$uuidBlock}-{$date}.log");
    }

    /**
     * Get log file path for an integration group on a specific date
     * Format: group_{service}_{uuid}-{date}.log
     */
    public static function getGroupLogPath(IntegrationGroup $group, ?string $date = null): string
    {
        $date = $date ?? now()->format('Y-m-d');
        $service = $group->service;
        $uuidBlock = $group->getUuidBlock();

        return storage_path("logs/group_{$service}_{$uuidBlock}-{$date}.log");
    }

    /**
     * Get log file path for an integration instance on a specific date
     * Format: integration_{service}_{instance_type}_{uuid}-{date}.log
     */
    public static function getIntegrationLogPath(Integration $integration, ?string $date = null): string
    {
        $date = $date ?? now()->format('Y-m-d');
        $service = $integration->service;
        $instanceType = $integration->instance_type ?? 'default';
        $uuidBlock = $integration->getUuidBlock();

        return storage_path("logs/integration_{$service}_{$instanceType}_{$uuidBlock}-{$date}.log");
    }

    /**
     * Get all log files for a user
     *
     * @return array<string>
     */
    public static function getUserLogFiles(User $user): array
    {
        $uuidBlock = $user->getUuidBlock();
        $pattern = storage_path("logs/user_{$uuidBlock}-*.log");

        return glob($pattern) ?: [];
    }

    /**
     * Get all log files for an integration group
     *
     * @return array<string>
     */
    public static function getGroupLogFiles(IntegrationGroup $group): array
    {
        $service = $group->service;
        $uuidBlock = $group->getUuidBlock();
        $pattern = storage_path("logs/group_{$service}_{$uuidBlock}-*.log");

        return glob($pattern) ?: [];
    }

    /**
     * Get all log files for an integration instance
     *
     * @return array<string>
     */
    public static function getIntegrationLogFiles(Integration $integration): array
    {
        $service = $integration->service;
        $instanceType = $integration->instance_type ?? 'default';
        $uuidBlock = $integration->getUuidBlock();
        $pattern = storage_path("logs/integration_{$service}_{$instanceType}_{$uuidBlock}-*.log");

        return glob($pattern) ?: [];
    }
}
