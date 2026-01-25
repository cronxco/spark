<?php

namespace App\Notifications;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class MigrationCompleted extends SparkNotification
{
    public function __construct(
        public Integration $integration,
        public ?array $details = null
    ) {}

    public function getNotificationType(): string
    {
        return 'migration_completed';
    }

    public function isPriority(): bool
    {
        return false;
    }

    public function getIcon(): string
    {
        return 'o-arrow-down-circle';
    }

    public function getColor(): string
    {
        return 'success';
    }

    public function getTitle(): string
    {
        return 'Historical Data Ready';
    }

    public function getMessage(): string
    {
        $serviceName = ucfirst($this->integration->service);
        $stats = $this->getStatsMessage();

        return "Your historical {$serviceName} data is now available and ready to explore{$stats}.";
    }

    public function getActionUrl(): ?string
    {
        return route('integrations.details', $this->integration->id);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $serviceName = ucfirst($this->integration->service);
        $message = (new MailMessage)
            ->subject("{$serviceName} Historical Data Ready")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Great news! Your historical {$serviceName} data has been imported and is ready to explore.");

        if ($this->details) {
            if (isset($this->details['events_imported'])) {
                $message->line('**Events imported:** ' . number_format($this->details['events_imported']));
            }

            if (isset($this->details['date_range'])) {
                $message->line("**Date range:** {$this->details['date_range']}");
            }

            if (isset($this->details['duration'])) {
                $message->line("**Import completed in:** {$this->details['duration']}");
            }
        }

        return $message
            ->action('Explore Your Data', $this->getActionUrl())
            ->line('Your integration will continue to sync new data automatically.');
    }

    protected function getStatsMessage(): string
    {
        if (! $this->details) {
            return '';
        }

        $parts = [];

        if (isset($this->details['events_imported'])) {
            $parts[] = number_format($this->details['events_imported']) . ' events imported';
        }

        if (isset($this->details['date_range'])) {
            $parts[] = $this->details['date_range'];
        }

        if (empty($parts)) {
            return '';
        }

        return ': ' . implode(', ', $parts);
    }
}
