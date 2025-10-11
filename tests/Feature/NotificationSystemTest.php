<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use App\Notifications\DataExportReady;
use App\Notifications\IntegrationAuthenticationFailed;
use App\Notifications\IntegrationCompleted;
use App\Notifications\IntegrationFailed;
use App\Notifications\MigrationCompleted;
use App\Notifications\MigrationFailed;
use App\Notifications\SystemMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_stores_notification_in_database()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $this->user->notify(new IntegrationCompleted($integration));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->user->id,
            'notifiable_type' => User::class,
        ]);

        $notification = $this->user->notifications->first();
        $this->assertEquals('Integration Completed', $notification->data['title']);
        $this->assertEquals('integration_completed', $notification->data['type']);
    }

    /** @test */
    public function priority_notifications_always_send_email()
    {
        Notification::fake();

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        // Disable email notifications for this type
        $this->user->disableEmailNotifications('integration_failed');

        // Priority notifications should still send
        $this->user->notify(new IntegrationFailed($integration, 'Test error'));

        Notification::assertSentTo($this->user, IntegrationFailed::class, function ($notification, $channels) {
            return in_array('mail', $channels);
        });
    }

    /** @test */
    public function non_priority_notifications_respect_email_preferences()
    {
        Notification::fake();

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        // Disable email notifications
        $this->user->disableEmailNotifications('integration_completed');

        $this->user->notify(new IntegrationCompleted($integration));

        Notification::assertSentTo($this->user, IntegrationCompleted::class, function ($notification, $channels) {
            return ! in_array('mail', $channels) && in_array('database', $channels);
        });
    }

    /** @test */
    public function notifications_respect_work_hours_setting()
    {
        Notification::fake();

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        // Enable work hours and delayed sending
        $this->user->updateNotificationPreferences([
            'email_enabled' => ['integration_completed' => true],
            'work_hours' => [
                'enabled' => true,
                'timezone' => 'UTC',
                'start' => '09:00',
                'end' => '17:00',
            ],
            'delayed_sending' => [
                'mode' => 'work_hours',
            ],
        ]);

        // Mock time outside work hours (e.g., 8am)
        $this->travelTo(now()->setTime(8, 0));

        $this->user->notify(new IntegrationCompleted($integration));

        // Email should be delayed (not sent)
        Notification::assertSentTo($this->user, IntegrationCompleted::class, function ($notification, $channels) {
            return ! in_array('mail', $channels);
        });
    }

    /** @test */
    public function user_can_check_if_in_work_hours()
    {
        $this->user->updateNotificationPreferences([
            'work_hours' => [
                'enabled' => true,
                'timezone' => 'UTC',
                'start' => '09:00',
                'end' => '17:00',
            ],
        ]);

        // During work hours
        $this->travelTo(now()->setTime(12, 0));
        $this->assertTrue($this->user->isInWorkHours());

        // Before work hours
        $this->travelTo(now()->setTime(8, 0));
        $this->assertFalse($this->user->isInWorkHours());

        // After work hours
        $this->travelTo(now()->setTime(18, 0));
        $this->assertFalse($this->user->isInWorkHours());
    }

    /** @test */
    public function daily_digest_mode_prevents_immediate_emails()
    {
        Notification::fake();

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $this->user->updateNotificationPreferences([
            'email_enabled' => ['integration_completed' => true],
            'delayed_sending' => [
                'mode' => 'daily_digest',
                'digest_time' => '09:00',
            ],
        ]);

        $this->user->notify(new IntegrationCompleted($integration));

        // Email should be delayed for digest
        Notification::assertSentTo($this->user, IntegrationCompleted::class, function ($notification, $channels) {
            return ! in_array('mail', $channels);
        });
    }

    /** @test */
    public function immediate_mode_sends_emails_right_away()
    {
        Notification::fake();

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $this->user->updateNotificationPreferences([
            'email_enabled' => ['integration_completed' => true],
            'delayed_sending' => [
                'mode' => 'immediate',
            ],
        ]);

        $this->user->notify(new IntegrationCompleted($integration));

        // Email should be sent immediately
        Notification::assertSentTo($this->user, IntegrationCompleted::class, function ($notification, $channels) {
            return in_array('mail', $channels);
        });
    }

    /** @test */
    public function user_can_get_notification_preferences()
    {
        $preferences = $this->user->getNotificationPreferences();

        $this->assertArrayHasKey('email_enabled', $preferences);
        $this->assertArrayHasKey('work_hours', $preferences);
        $this->assertArrayHasKey('delayed_sending', $preferences);
    }

    /** @test */
    public function user_can_update_notification_preferences()
    {
        $this->user->updateNotificationPreferences([
            'email_enabled' => [
                'integration_completed' => false,
                'data_export_ready' => true,
            ],
            'work_hours' => [
                'enabled' => true,
                'timezone' => 'America/New_York',
                'start' => '08:00',
                'end' => '18:00',
            ],
        ]);

        $preferences = $this->user->getNotificationPreferences();

        $this->assertFalse($preferences['email_enabled']['integration_completed']);
        $this->assertTrue($preferences['email_enabled']['data_export_ready']);
        $this->assertEquals('America/New_York', $preferences['work_hours']['timezone']);
    }

    /** @test */
    public function user_can_enable_and_disable_email_notifications()
    {
        $this->user->enableEmailNotifications('integration_completed');
        $this->assertTrue($this->user->hasEmailNotificationsEnabled('integration_completed'));

        $this->user->disableEmailNotifications('integration_completed');
        $this->assertFalse($this->user->hasEmailNotificationsEnabled('integration_completed'));
    }

    /** @test */
    public function integration_completed_notification_has_correct_metadata()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $notification = new IntegrationCompleted($integration, ['events_synced' => 100]);

        $this->assertEquals('integration_completed', $notification->getNotificationType());
        $this->assertEquals('o-check-circle', $notification->getIcon());
        $this->assertEquals('success', $notification->getColor());
        $this->assertFalse($notification->isPriority());
        $this->assertStringContainsString('completed successfully', $notification->getMessage());
    }

    /** @test */
    public function integration_failed_notification_is_priority()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $notification = new IntegrationFailed($integration, 'API error');

        $this->assertTrue($notification->isPriority());
        $this->assertEquals('o-x-circle', $notification->getIcon());
        $this->assertEquals('error', $notification->getColor());
    }

    /** @test */
    public function data_export_ready_notification_includes_download_url()
    {
        $downloadUrl = 'https://example.com/download/123';

        $notification = new DataExportReady('User Data', $downloadUrl);

        $this->assertEquals($downloadUrl, $notification->getActionUrl());
        $this->assertEquals('o-arrow-down-tray', $notification->getIcon());
        $this->assertEquals('info', $notification->getColor());
    }

    /** @test */
    public function system_maintenance_notification_is_priority()
    {
        $notification = new SystemMaintenance('Update', 'System will be down');

        $this->assertTrue($notification->isPriority());
        $this->assertEquals('o-wrench-screwdriver', $notification->getIcon());
        $this->assertEquals('warning', $notification->getColor());
    }

    /** @test */
    public function notification_includes_action_url_in_database_data()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $this->user->notify(new IntegrationCompleted($integration));

        $notification = $this->user->notifications->first();
        $this->assertNotNull($notification->data['action_url']);
        $this->assertStringContainsString((string) $integration->id, $notification->data['action_url']);
    }

    /** @test */
    public function unread_notifications_can_be_retrieved()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $this->user->notify(new IntegrationCompleted($integration));
        $this->user->notify(new IntegrationCompleted($integration));

        $unread = $this->user->unreadNotifications;

        $this->assertCount(2, $unread);
    }

    /** @test */
    public function notifications_can_be_marked_as_read()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $this->user->notify(new IntegrationCompleted($integration));

        $notification = $this->user->unreadNotifications->first();
        $notification->markAsRead();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertCount(0, $this->user->unreadNotifications);
    }

    /** @test */
    public function email_notification_contains_correct_content()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $notification = new IntegrationCompleted($integration, [
            'events_synced' => 150,
            'duration' => '2m 30s',
        ]);

        $mailMessage = $notification->toMail($this->user);

        $this->assertStringContainsString('Hello ' . $this->user->name, $mailMessage->greeting);
        $this->assertStringContainsString('completed successfully', collect($mailMessage->introLines)->first());
        $this->assertEquals('View Integration', $mailMessage->actionText);
    }

    /** @test */
    public function work_hours_disabled_means_always_in_work_hours()
    {
        $this->user->updateNotificationPreferences([
            'work_hours' => [
                'enabled' => false,
            ],
        ]);

        // Any time should be considered "in work hours"
        $this->travelTo(now()->setTime(2, 0)); // 2 AM
        $this->assertTrue($this->user->isInWorkHours());

        $this->travelTo(now()->setTime(23, 0)); // 11 PM
        $this->assertTrue($this->user->isInWorkHours());
    }

    /** @test */
    public function get_delayed_sending_mode_returns_correct_value()
    {
        $this->user->updateNotificationPreferences([
            'delayed_sending' => ['mode' => 'work_hours'],
        ]);

        $this->assertEquals('work_hours', $this->user->getDelayedSendingMode());

        $this->user->updateNotificationPreferences([
            'delayed_sending' => ['mode' => 'daily_digest'],
        ]);

        $this->assertEquals('daily_digest', $this->user->getDelayedSendingMode());
    }

    /** @test */
    public function get_digest_time_returns_correct_value()
    {
        $this->user->updateNotificationPreferences([
            'delayed_sending' => ['digest_time' => '10:30'],
        ]);

        $this->assertEquals('10:30', $this->user->getDigestTime());
    }

    /** @test */
    public function notification_with_details_stores_them_in_database()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $details = [
            'events_synced' => 150,
            'blocks_created' => 75,
            'duration' => '2m 30s',
        ];

        $this->user->notify(new IntegrationCompleted($integration, $details));

        $notification = $this->user->notifications->first();

        // Details are included in the notification data
        $this->assertArrayHasKey('title', $notification->data);
        $this->assertArrayHasKey('message', $notification->data);
    }

    /** @test */
    public function integration_authentication_failed_notification_is_priority()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $notification = new IntegrationAuthenticationFailed(
            $integration,
            'Your connection has expired',
            ['error_code' => 'invalid_grant']
        );

        $this->assertTrue($notification->isPriority());
        $this->assertEquals('o-shield-exclamation', $notification->getIcon());
        $this->assertEquals('error', $notification->getColor());
        $this->assertEquals('integration_authentication_failed', $notification->getNotificationType());
        $this->assertStringContainsString('re-authorized', $notification->getMessage());
    }

    /** @test */
    public function integration_authentication_failed_always_sends_email()
    {
        Notification::fake();

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        // Disable email notifications for this type
        $this->user->disableEmailNotifications('integration_authentication_failed');

        // Priority notifications should still send
        $this->user->notify(new IntegrationAuthenticationFailed(
            $integration,
            'Your connection has expired'
        ));

        Notification::assertSentTo($this->user, IntegrationAuthenticationFailed::class, function ($notification, $channels) {
            return in_array('mail', $channels);
        });
    }

    /** @test */
    public function migration_completed_notification_has_correct_metadata()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $notification = new MigrationCompleted($integration, [
            'events_imported' => 1500,
            'date_range' => 'Jan 2023 - Dec 2024',
        ]);

        $this->assertEquals('migration_completed', $notification->getNotificationType());
        $this->assertEquals('o-arrow-down-circle', $notification->getIcon());
        $this->assertEquals('success', $notification->getColor());
        $this->assertFalse($notification->isPriority());
        $this->assertStringContainsString('completed successfully', $notification->getMessage());
        $this->assertStringContainsString('1,500', $notification->getMessage());
    }

    /** @test */
    public function migration_completed_respects_email_preferences()
    {
        Notification::fake();

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        // Disable email notifications
        $this->user->disableEmailNotifications('migration_completed');

        $this->user->notify(new MigrationCompleted($integration));

        Notification::assertSentTo($this->user, MigrationCompleted::class, function ($notification, $channels) {
            return ! in_array('mail', $channels) && in_array('database', $channels);
        });
    }

    /** @test */
    public function migration_failed_notification_is_priority()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $notification = new MigrationFailed(
            $integration,
            'API authentication failed',
            ['attempted_date_range' => 'Jan 2023 - Dec 2024']
        );

        $this->assertTrue($notification->isPriority());
        $this->assertEquals('o-exclamation-triangle', $notification->getIcon());
        $this->assertEquals('error', $notification->getColor());
        $this->assertEquals('migration_failed', $notification->getNotificationType());
        $this->assertStringContainsString('failed', $notification->getMessage());
    }

    /** @test */
    public function migration_failed_always_sends_email()
    {
        Notification::fake();

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        // Disable email notifications for this type
        $this->user->disableEmailNotifications('migration_failed');

        // Priority notifications should still send
        $this->user->notify(new MigrationFailed(
            $integration,
            'Migration failed permanently'
        ));

        Notification::assertSentTo($this->user, MigrationFailed::class, function ($notification, $channels) {
            return in_array('mail', $channels);
        });
    }

    /** @test */
    public function migration_completed_email_contains_stats()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $notification = new MigrationCompleted($integration, [
            'events_imported' => 1500,
            'date_range' => 'Jan 2023 - Dec 2024',
            'duration' => '5m 30s',
        ]);

        $mailMessage = $notification->toMail($this->user);

        $this->assertStringContainsString('Hello ' . $this->user->name, $mailMessage->greeting);
        $this->assertStringContainsString('1,500', collect($mailMessage->introLines)->implode(' '));
        $this->assertStringContainsString('Jan 2023 - Dec 2024', collect($mailMessage->introLines)->implode(' '));
        $this->assertEquals('View Integration', $mailMessage->actionText);
    }

    /** @test */
    public function authentication_failed_email_contains_re_auth_action()
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $notification = new IntegrationAuthenticationFailed(
            $integration,
            'Your connection has expired'
        );

        $mailMessage = $notification->toMail($this->user);

        $this->assertStringContainsString('Hello ' . $this->user->name, $mailMessage->greeting);
        $this->assertStringContainsString('re-authorize', collect($mailMessage->introLines)->implode(' '));
        $this->assertEquals('Re-authorize Connection', $mailMessage->actionText);
    }
}
