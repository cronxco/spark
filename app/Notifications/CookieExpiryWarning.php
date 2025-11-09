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
        // High priority if expires in 3 days or less
        return $this->daysUntilExpiry <= 3;
    }

    public function getIcon(): string
    {
        return 'o-exclamation-triangle';
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
        return 'Cookie Expiring Soon';
    }

    public function getMessage(): string
    {
        $expiryDate = Carbon::parse($this->expiresAt);

        if ($this->daysUntilExpiry === 0) {
            return "Cookies for {$this->domain} expire today ({$expiryDate->format('M j')}).";
        } elseif ($this->daysUntilExpiry === 1) {
            return "Cookies for {$this->domain} expire tomorrow ({$expiryDate->format('M j')}).";
        } else {
            return "Cookies for {$this->domain} expire in {$this->daysUntilExpiry} days ({$expiryDate->format('M j')}).";
        }
    }

    public function getActionUrl(): ?string
    {
        return route('bookmarks.fetch') . '?tab=cookies';
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
