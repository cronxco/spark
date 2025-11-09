<?php

namespace App\Notifications;

use App\Models\EventObject;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class FetchMultipleFailures extends SparkNotification
{
    public function __construct(
        public EventObject $webpage,
        public int $consecutiveFailures,
        public string $errorMessage,
        public ?string $errorType = null
    ) {}

    public function getNotificationType(): string
    {
        return 'fetch_multiple_failures';
    }

    public function isPriority(): bool
    {
        // High priority after 5+ failures
        return $this->consecutiveFailures >= 5;
    }

    public function getIcon(): string
    {
        return 'o-exclamation-circle';
    }

    public function getColor(): string
    {
        return 'error';
    }

    public function getTitle(): string
    {
        return 'Fetch Failed Multiple Times';
    }

    public function getMessage(): string
    {
        $title = $this->webpage->title;
        $domain = $this->webpage->metadata['domain'] ?? parse_url($this->webpage->url, PHP_URL_HOST);

        return "Failed to fetch {$title} ({$domain}) {$this->consecutiveFailures} times: {$this->errorMessage}";
    }

    public function getActionUrl(): ?string
    {
        return route('bookmarks.fetch') . '?domain=' . urlencode($this->webpage->metadata['domain'] ?? '');
    }

    /**
     * Get the mail representation of the notification
     */
    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject('Fetch Failures: ' . $this->webpage->title)
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->getMessage());

        // Add specific suggestions based on error type
        if ($this->errorType === 'paywall') {
            $mail->line('This URL appears to be behind a paywall.')
                ->line('Try adding authentication cookies for this domain to access the content.');
        } elseif ($this->errorType === 'robot_check') {
            $mail->line('This URL has anti-bot protection.')
                ->line('Try adding cookies or updating your User-Agent header for this domain.');
        } elseif ($this->errorType === 'network') {
            $mail->line('Network connectivity issues detected.')
                ->line('This may be a temporary issue. The URL will be retried on the next scheduled run.');
        } elseif ($this->errorType === 'http') {
            $mail->line('HTTP error encountered.')
                ->line('The URL may no longer exist or may have moved to a new location.');
        }

        $mail->action('Manage Bookmarks', $this->getActionUrl())
            ->line('You may want to check or disable this URL if the issue persists.');

        if ($this->consecutiveFailures >= 5) {
            $mail->line('⚠️ This URL has been automatically disabled after multiple failures.');
        }

        return $mail;
    }
}
