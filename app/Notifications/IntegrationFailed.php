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
        return parent::isPriority();
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
        $name = $this->integration->name ?? ucfirst($this->integration->service);

        return "{$name} stopped syncing";
    }

    public function getMessage(): string
    {
        $name = $this->integration->name ?? ucfirst($this->integration->service);

        return "Your {$name} data may be out of date. Spark will retry automatically.";
    }

    public function getActionUrl(): ?string
    {
        return route('integrations.details', $this->integration->id);
    }

    public function getEntityType(): ?string
    {
        return 'integration';
    }

    public function getEntityId(): ?string
    {
        return (string) $this->integration->id;
    }

    public function getGroupKey(): ?string
    {
        return "integration_failed:{$this->integration->id}";
    }

    public function getTechnicalDetail(): ?string
    {
        return $this->sanitiseTechnicalDetail($this->errorMessage);
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
            ->line("Your {$name} data may be out of date. Spark will retry automatically.")
            ->action('View Integration', $this->getActionUrl());

        $mail->line('Please check your integration settings and try again.');

        return $mail;
    }
}
