<?php

namespace App\Notifications;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class IntegrationFailed extends SparkNotification
{
    public function __construct(
        public Integration $integration,
        public string $errorMessage,
        public ?array $details = null
    ) {}

    public function getNotificationType(): string
    {
        return 'integration_failed';
    }

    public function isPriority(): bool
    {
        return true;
    }

    public function getIcon(): string
    {
        return 'fas.circle-xmark';
    }

    public function getColor(): string
    {
        return 'error';
    }

    public function getTitle(): string
    {
        return 'Integration Failed';
    }

    public function getMessage(): string
    {
        $name = $this->integration->name ?? ucfirst($this->integration->service);

        return "{$name} integration failed: {$this->errorMessage}";
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
            ->error()
            ->subject("Integration Failed: {$name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your {$name} integration encountered an error:")
            ->line($this->errorMessage)
            ->action('View Integration', $this->getActionUrl());

        if ($this->details) {
            $mail->line('Additional Details:');
            foreach ($this->details as $key => $value) {
                $mail->line(ucfirst(str_replace('_', ' ', $key)).': '.$value);
            }
        }

        $mail->line('Please check your integration settings and try again.');

        return $mail;
    }
}
