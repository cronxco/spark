<?php

namespace App\Notifications;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class MigrationFailed extends SparkNotification
{
    public function __construct(
        public Integration $integration,
        public string $errorMessage,
        public ?array $details = null
    ) {}

    public function getNotificationType(): string
    {
        return 'migration_failed';
    }

    public function isPriority(): bool
    {
        return true;
    }

    public function getIcon(): string
    {
        return 'fas-triangle-exclamation';
    }

    public function getColor(): string
    {
        return 'error';
    }

    public function getTitle(): string
    {
        return 'Historical Data Import Failed';
    }

    public function getMessage(): string
    {
        $serviceName = ucfirst($this->integration->service);

        return "Your {$serviceName} historical data import failed: {$this->errorMessage}";
    }

    public function getActionUrl(): ?string
    {
        return route('integrations.details', $this->integration->id);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $serviceName = ucfirst($this->integration->service);

        $message = (new MailMessage)
            ->subject("{$serviceName} Historical Data Import Failed")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Unfortunately, your {$serviceName} historical data import has failed.")
            ->line("**Error:** {$this->errorMessage}");

        if ($this->details && isset($this->details['attempted_date_range'])) {
            $message->line("**Attempted date range:** {$this->details['attempted_date_range']}");
        }

        return $message
            ->action('View Integration', $this->getActionUrl())
            ->line('You can try running the migration again, or contact support if the issue persists.');
    }
}
