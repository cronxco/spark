<?php

namespace App\Notifications;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class IntegrationAuthenticationFailed extends SparkNotification
{
    public function __construct(
        public Integration $integration,
        public string $errorMessage,
        public ?array $details = null
    ) {}

    public function getNotificationType(): string
    {
        return 'integration_authentication_failed';
    }

    public function isPriority(): bool
    {
        return true;
    }

    public function getIcon(): string
    {
        return 'o-shield-exclamation';
    }

    public function getColor(): string
    {
        return 'error';
    }

    public function getTitle(): string
    {
        return 'Authentication Required';
    }

    public function getMessage(): string
    {
        $serviceName = ucfirst($this->integration->service);

        return "Your {$serviceName} connection needs to be re-authorized. {$this->errorMessage}";
    }

    public function getActionUrl(): ?string
    {
        return route('integrations.details', $this->integration->id);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $serviceName = ucfirst($this->integration->service);

        return (new MailMessage)
            ->subject("Action Required: {$serviceName} Authentication Failed")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your {$serviceName} integration has lost authentication and needs to be reconnected.")
            ->line("**Error:** {$this->errorMessage}")
            ->line('Please click the button below to re-authorize your connection and resume data syncing.')
            ->action('Re-authorize Connection', $this->getActionUrl())
            ->line('If you continue to experience issues, please contact support.');
    }
}
