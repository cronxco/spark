<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class SystemMaintenance extends SparkNotification
{
    public function __construct(
        public string $maintenanceType,
        public string $message,
        public ?array $details = null
    ) {}

    public function getNotificationType(): string
    {
        return 'system_maintenance';
    }

    public function isPriority(): bool
    {
        return true;
    }

    public function getIcon(): string
    {
        return 'o-wrench-screwdriver';
    }

    public function getColor(): string
    {
        return 'warning';
    }

    public function getTitle(): string
    {
        return 'System Maintenance';
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getActionUrl(): ?string
    {
        return null;
    }

    /**
     * Get the mail representation of the notification
     */
    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("System Maintenance: {$this->maintenanceType}")
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->message);

        if ($this->details) {
            $mail->line('Details:');
            foreach ($this->details as $key => $value) {
                $mail->line(ucfirst(str_replace('_', ' ', $key)) . ': ' . $value);
            }
        }

        $mail->line('Thank you for your patience.');

        return $mail;
    }
}
