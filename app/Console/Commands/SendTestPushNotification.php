<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Console\Command;

class SendTestPushNotification extends Command
{
    protected $signature = 'push:test
                            {--user= : The user ID or email to send the notification to}
                            {--all : Send to all users with push subscriptions}';

    protected $description = 'Send a test push notification to verify the push notification system';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->sendToAllSubscribedUsers();
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

        return $this->sendToUser($user);
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

    protected function sendToUser(User $user): int
    {
        $subscriptionCount = $user->pushSubscriptions()->count();

        if ($subscriptionCount === 0) {
            $this->error("User {$user->email} has no push subscriptions registered.");
            $this->info('The user needs to enable push notifications in their browser first.');

            return self::FAILURE;
        }

        $this->info("Sending test notification to {$user->email}...");
        $this->info("  - {$subscriptionCount} device(s) registered");

        try {
            $user->notify(new TestPushNotification);
            $this->info('Test notification sent successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to send notification: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function sendToAllSubscribedUsers(): int
    {
        $users = User::whereHas('pushSubscriptions')->get();

        if ($users->isEmpty()) {
            $this->warn('No users have push subscriptions registered.');

            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} user(s) with push subscriptions.");

        if (! $this->confirm('Send test notification to all of them?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $successCount = 0;
        $failCount = 0;

        $this->withProgressBar($users, function (User $user) use (&$successCount, &$failCount) {
            try {
                $user->notify(new TestPushNotification);
                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
            }
        });

        $this->newLine(2);
        $this->info("Sent: {$successCount}, Failed: {$failCount}");

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
