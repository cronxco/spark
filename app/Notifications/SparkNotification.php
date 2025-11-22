<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class SparkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification type identifier for preferences
     */
    abstract public function getNotificationType(): string;

    /**
     * Get the notification priority
     * Priority notifications always send immediately via all channels
     */
    public function isPriority(): bool
    {
        return false;
    }

    /**
     * Get the notification's delivery channels
     */
    public function via(User $notifiable): array
    {
        $channels = ['database'];

        // Priority notifications always send via email
        if ($this->isPriority()) {
            $channels[] = 'mail';

            return $channels;
        }

        // Check user preferences for email
        if ($notifiable->hasEmailNotificationsEnabled($this->getNotificationType())) {
            // Handle delayed sending based on work hours
            if ($this->shouldDelayEmail($notifiable)) {
                // Don't send email now - it will be queued for later
                return $channels;
            }

            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get notification icon for UI display
     */
    public function getIcon(): string
    {
        return 'fas.bell';
    }

    /**
     * Get notification color for UI display
     */
    public function getColor(): string
    {
        return 'primary';
    }

    /**
     * Get the notification title for UI display
     */
    abstract public function getTitle(): string;

    /**
     * Get the notification message for UI display
     */
    abstract public function getMessage(): string;

    /**
     * Get the notification action URL (optional)
     */
    public function getActionUrl(): ?string
    {
        return null;
    }

    /**
     * Get the array representation of the notification for database storage
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => $this->getNotificationType(),
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'action_url' => $this->getActionUrl(),
            'priority' => $this->isPriority(),
        ];
    }

    /**
     * Determine if email should be delayed based on user preferences
     */
    protected function shouldDelayEmail(User $notifiable): bool
    {
        $mode = $notifiable->getDelayedSendingMode();

        // Always send immediately
        if ($mode === 'immediate') {
            return false;
        }

        // Send in daily digest
        if ($mode === 'daily_digest') {
            return true;
        }

        // Send only during work hours
        if ($mode === 'work_hours') {
            return ! $notifiable->isInWorkHours();
        }

        return false;
    }
}
