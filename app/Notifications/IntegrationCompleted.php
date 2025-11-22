<?php

namespace App\Notifications;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class IntegrationCompleted extends SparkNotification
{
    public function __construct(
        public Integration $integration,
        public ?array $details = null
    ) {}

    public function getNotificationType(): string
    {
        return 'integration_completed';
    }

    public function getIcon(): string
    {
        return 'fas-circle-check';
    }

    public function getColor(): string
    {
        return 'success';
    }

    public function getTitle(): string
    {
        return 'Integration Completed';
    }

    public function getMessage(): string
    {
        $name = $this->integration->name ?? ucfirst($this->integration->service);

        return "{$name} integration has completed successfully.";
    }

    public function getActionUrl(): ?string
    {
        return route('integrations.details', $this->integration->id);
    }

    /**
     * Get the mail representation of the notification
     */
    public function toMail(User $notifiable): MailMessage
    {
        $name = $this->integration->name ?? ucfirst($this->integration->service);

        $mail = (new MailMessage)
            ->subject("Integration Completed: {$name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your {$name} integration has completed successfully.")
            ->action('View Integration', $this->getActionUrl());

        if ($this->details) {
            $mail->line('Details:');
            foreach ($this->details as $key => $value) {
                $mail->line(ucfirst(str_replace('_', ' ', $key)) . ': ' . $value);
            }
        }

        return $mail;
    }
}
