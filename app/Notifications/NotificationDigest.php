<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The single daily email a "Daily Digest" user receives in place of one email
 * per notification. Mail only: each item is already in the notification centre,
 * so this is a delivery of existing records, not a new one.
 */
class NotificationDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{title: string, body: ?string}>  $items
     */
    public function __construct(public array $items) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'notifications'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $count = count($this->items);

        $mail = (new MailMessage)
            ->subject($count === 1 ? 'Your Spark digest: 1 notification' : "Your Spark digest: {$count} notifications")
            ->greeting("Hello {$notifiable->name}!")
            ->line($count === 1
                ? 'Here is the notification from the last day.'
                : "Here are the {$count} notifications from the last day.");

        foreach ($this->items as $item) {
            $line = '**' . $item['title'] . '**';

            if (filled($item['body'])) {
                $line .= ' — ' . $item['body'];
            }

            $mail->line($line);
        }

        return $mail->action('Open notifications', route('notifications.index'));
    }
}
