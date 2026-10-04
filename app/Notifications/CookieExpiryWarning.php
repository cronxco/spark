<?php

namespace App\Notifications;

use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

class CookieExpiryWarning extends SparkNotification
{
    public function __construct(
        public IntegrationGroup $group,
        public string $domain,
        public string $expiresAt,
        public int $daysUntilExpiry
    ) {}

    public function getNotificationType(): string
    {
        return 'cookie_expiry_warning';
    }

    public function isPriority(): bool
    {
        return parent::isPriority();
    }

    public function getIcon(): string
    {
        return 'fas.triangle-exclamation';
    }

    public function getColor(): string
    {
        if ($this->daysUntilExpiry <= 1) {
            return 'error';
        } elseif ($this->daysUntilExpiry <= 3) {
            return 'warning';
        } else {
            return 'info';
        }
    }

    public function getTitle(): string
    {
        return "Refresh your sign-in for {$this->domain}";
    }

    public function getMessage(): string
    {
        if ($this->daysUntilExpiry === 0) {
            return 'Spark may stop updating this site today. Refresh the saved sign-in to keep it working.';
        } elseif ($this->daysUntilExpiry === 1) {
            return 'Spark may stop updating this site tomorrow. Refresh the saved sign-in to keep it working.';
        } else {
            return "Spark may stop updating this site in {$this->daysUntilExpiry} days. Refresh the saved sign-in to keep it working.";
        }
    }

    public function getActionUrl(): ?string
    {
        return route('bookmarks') . '?tab=cookies';
    }

    public function getGroupKey(): ?string
    {
        return "cookie_expiry_warning:{$this->group->id}:{$this->domain}";
    }

    /**
     * Get the mail representation of the notification
     */
    public function toMail(User $notifiable): MailMessage
    {
        $expiryDate = Carbon::parse($this->expiresAt);
        $subject = $this->daysUntilExpiry <= 1 ? 'Cookies Expiring Soon!' : 'Cookie Expiry Reminder';

        return (new MailMessage)
            ->warning()
            ->subject($subject)
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->getMessage())
            ->line("To ensure uninterrupted content fetching from {$this->domain}, please update your cookies before they expire.")
            ->action('Manage Cookies', $this->getActionUrl())
            ->line('You can update your cookies in the Fetch bookmarks settings.');
    }
}
