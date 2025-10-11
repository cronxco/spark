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
        return 'Historical Data Import Complete';
    }

    public function getMessage(): string
    {
        $serviceName = ucfirst($this->integration->service);
        $stats = $this->getStatsMessage();

        return "Your {$serviceName} historical data import has completed successfully{$stats}.";
    }

    public function getActionUrl(): ?string
    {
        return route('integrations.details', $this->integration->id);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $serviceName = ucfirst($this->integration->service);
        $message = (new MailMessage)
            ->subject("{$serviceName} Historical Data Import Complete")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Great news! Your {$serviceName} historical data import has completed successfully.");

        if ($this->details) {
            if (isset($this->details['events_imported'])) {
                $message->line('**Events imported:** ' . number_format($this->details['events_imported']));
            }

            if (isset($this->details['date_range'])) {
                $message->line("**Date range:** {$this->details['date_range']}");
            }

            if (isset($this->details['duration'])) {
                $message->line("**Duration:** {$this->details['duration']}");
            }
        }

        return $message
            ->action('View Integration', $this->getActionUrl())
            ->line('Your data is now available to view and explore.');
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
