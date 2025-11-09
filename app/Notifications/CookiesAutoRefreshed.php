<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CookiesAutoRefreshed extends Notification
{
    use Queueable;

    public function __construct(
        public string $domain,
        public int $cookieCount,
        public Carbon $newExpiryDate
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'info',
            'title' => 'Cookies Auto-Refreshed',
            'message' => "Cookies for {$this->domain} were automatically refreshed ({$this->cookieCount} cookies). New expiry: {$this->newExpiryDate->format('M j, Y')}",
            'data' => [
                'domain' => $this->domain,
                'cookie_count' => $this->cookieCount,
                'new_expiry' => $this->newExpiryDate->toIso8601String(),
            ],
        ];
    }
}
