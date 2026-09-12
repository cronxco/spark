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
        return parent::isPriority();
    }

    public function getIcon(): string
    {
        return 'fas.triangle-exclamation';
    }

    public function getColor(): string
    {
        return 'error';
    }

    public function getTitle(): string
    {
        return ucfirst($this->integration->service) . ' import stopped';
    }

    public function getMessage(): string
    {
        $serviceName = ucfirst($this->integration->service);

        return "Some {$serviceName} history may be missing. Retry the import or view the connection.";
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
        return "migration_failed:{$this->integration->id}";
    }

    public function getTechnicalDetail(): ?string
    {
        return $this->sanitiseTechnicalDetail($this->errorMessage);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $serviceName = ucfirst($this->integration->service);

        $message = (new MailMessage)
            ->subject("{$serviceName} Historical Data Import Failed")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Some {$serviceName} history may be missing. Retry the import or view the connection.");

        if ($this->details && isset($this->details['attempted_date_range'])) {
            $message->line("**Attempted date range:** {$this->details['attempted_date_range']}");
        }

        return $message
            ->action('View Integration', $this->getActionUrl())
            ->line('You can try running the migration again, or contact support if the issue persists.');
    }
}
