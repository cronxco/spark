<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TestPushNotification;
use Exception;
use Illuminate\Console\Command;

class SendTestPushNotification extends Command
{
    protected $signature = 'push:test
                            {--user= : The user ID or email to send the notification to}
                            {--all : Send to all users with push subscriptions}
                            {--platform=all : Platform filter — web, ios, or all}
                            {--sync : Send synchronously (bypass queue) for debugging}';

    protected $description = 'Send a test push notification to verify the push notification system';

    public function handle(): int
    {
        $platform = strtolower((string) $this->option('platform'));

        if (! in_array($platform, ['web', 'ios', 'all'], true)) {
            $this->error("Invalid platform '{$platform}'. Use one of: web, ios, all.");

            return self::INVALID;
        }

        if ($this->option('all')) {
            return $this->sendToAllSubscribedUsers($platform);
        }

        $userIdentifier = $this->option('user');

        if (! $userIdentifier) {
            $userIdentifier = $this->ask('Enter user ID or email address');
        }

        $user = $this->findUser($userIdentifier);

        if (! $user) {
            $this->error("User not found: {$userIdentifier}");

            return self::FAILURE;
        }

        return $this->sendToUser($user, $platform);
    }

    protected function findUser(string $identifier): ?User
    {
        // If it looks like an email, search by email first
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $identifier)->first();
        }

        // Otherwise try to find by ID (UUID)
        return User::find($identifier);
    }

    protected function sendToUser(User $user, string $platform = 'all'): int
    {
        $subscriptions = $user->pushSubscriptions();

        if ($platform !== 'all') {
            $subscriptions = $subscriptions->where('device_type', $platform);
        }

        $subscriptionCount = $subscriptions->count();

        if ($subscriptionCount === 0) {
            $this->error("User {$user->email} has no '{$platform}' push subscriptions registered.");
            $this->info('The user needs to enable push notifications on that platform first.');

            return self::FAILURE;
        }

        $this->info("Sending test notification to {$user->email}...");
        $this->info("  - {$subscriptionCount} {$platform} device(s) registered");

        try {
            $notification = new TestPushNotification($platform);

            if ($this->option('sync')) {
                $this->info('  - Sending synchronously (--sync mode)');
                $user->notifyNow($notification);
            } else {
                $user->notify($notification);
            }

            $this->info('Test notification sent successfully!');

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to send notification: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    protected function sendToAllSubscribedUsers(string $platform = 'all'): int
    {
        $query = User::whereHas('pushSubscriptions', function ($q) use ($platform) {
            if ($platform !== 'all') {
                $q->where('device_type', $platform);
            }
        });

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->warn("No users have '{$platform}' push subscriptions registered.");

            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} user(s) with {$platform} push subscriptions.");

        if (! $this->confirm('Send test notification to all of them?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $successCount = 0;
        $failCount = 0;

        $this->withProgressBar($users, function (User $user) use (&$successCount, &$failCount, $platform) {
            try {
                $user->notify(new TestPushNotification($platform));
                $successCount++;
            } catch (Exception $e) {
                $failCount++;
            }
        });

        $this->newLine(2);
        $this->info("Sent: {$successCount}, Failed: {$failCount}");

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
