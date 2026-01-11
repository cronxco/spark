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

        // Check if this is a GoCardless EUA expiry
        if ($this->integration->service === 'gocardless' &&
            ($this->details['eua_expired'] ?? false)) {
            $bankName = $this->details['bank_name'] ?? 'bank';

            return "Your {$bankName} connection has expired. {$this->errorMessage}";
        }

        return "Your {$serviceName} connection needs to be re-authorized. {$this->errorMessage}";
    }

    public function getActionUrl(): ?string
    {
        return route('integrations.details', $this->integration->id);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $serviceName = ucfirst($this->integration->service);
        $isEuaExpiry = $this->integration->service === 'gocardless' &&
                       ($this->details['eua_expired'] ?? false);

        $subject = $isEuaExpiry
            ? 'Action Required: Bank Connection Expired'
            : "Action Required: {$serviceName} Authentication Failed";

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name}!");

        if ($isEuaExpiry) {
            $bankName = $this->details['bank_name'] ?? 'bank';
            $mail->line("Your {$bankName} connection has expired and needs to be renewed.")
                ->line('This is required every 90 days for security purposes. Your transaction history will remain intact.')
                ->line('Click the button below to reconnect your account and resume syncing.');
        } else {
            $mail->line("Your {$serviceName} integration has lost authentication and needs to be reconnected.")
                ->line("**Error:** {$this->errorMessage}")
                ->line('Please click the button below to re-authorize your connection and resume data syncing.');
        }

        return $mail->action($isEuaExpiry ? 'Reconnect Bank' : 'Re-authorize Connection', $this->getActionUrl())
            ->line('If you continue to experience issues, please contact support.');
    }
}
