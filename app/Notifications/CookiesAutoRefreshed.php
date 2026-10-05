<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

class CookiesAutoRefreshed extends SparkNotification
{
    public function __construct(
        public string $domain,
        public int $cookieCount,
        public Carbon $newExpiryDate
    ) {}

    public function getNotificationType(): string
    {
        return 'cookie_auto_refreshed';
    }

    public function getTitle(): string
    {
        return "Saved sign-in refreshed for {$this->domain}";
    }

    public function getMessage(): string
    {
        return "Spark can keep updating this site. The refreshed sign-in is expected to work until {$this->newExpiryDate->format('j M Y')}.";
    }

    public function getIcon(): string
    {
        return 'o-check-circle';
    }

    public function getColor(): string
    {
        return 'success';
    }

    public function getActionUrl(): ?string
    {
        return route('bookmarks') . '?tab=cookies';
    }

    public function getGroupKey(): ?string
    {
        return "cookie_auto_refreshed:{$this->domain}";
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->success()
            ->subject($this->getTitle())
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->getMessage())
            ->line("Spark refreshed {$this->cookieCount} saved " . ($this->cookieCount === 1 ? 'cookie' : 'cookies') . " for {$this->domain}.")
            ->action('Manage Saved Logins', $this->getActionUrl());
    }

    public function toArray(User $notifiable): array
    {
        return [
            ...parent::toArray($notifiable),
            'domain' => $this->domain,
            'new_expiry' => $this->newExpiryDate->toIso8601String(),
        ];
    }
}
