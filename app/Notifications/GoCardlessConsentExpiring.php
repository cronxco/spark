<?php

namespace App\Notifications;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

class GoCardlessConsentExpiring extends SparkNotification
{
    public function __construct(
        public IntegrationGroup $group,
        public Integration $integration,
        public Carbon $expiresAt,
        public int $daysUntilExpiry
    ) {}

    public function getNotificationType(): string
    {
        return 'gocardless_consent_expiring';
    }

    public function getIcon(): string
    {
        return 'fas.building-columns';
    }

    public function getColor(): string
    {
        return $this->daysUntilExpiry <= 1 ? 'error' : 'warning';
    }

    public function getTitle(): string
    {
        return "Reconnect {$this->bankName()} soon";
    }

    public function getMessage(): string
    {
        $when = match (true) {
            $this->daysUntilExpiry === 0 => 'today',
            $this->daysUntilExpiry === 1 => 'tomorrow',
            default => "in {$this->daysUntilExpiry} days",
        };

        return "Your {$this->bankName()} connection expires {$when}. Reconnect it to keep transactions syncing.";
    }

    public function getActionUrl(): ?string
    {
        return route('integrations.details', $this->integration->id);
    }

    public function getGroupKey(): ?string
    {
        return "gocardless_consent_expiring:{$this->group->id}";
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->warning()
            ->subject("Reconnect {$this->bankName()} before {$this->expiresAt->format('j M')}")
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->getMessage())
            ->action('Reconnect', $this->getActionUrl());
    }

    private function bankName(): string
    {
        return $this->group->auth_metadata['gocardless_institution_name'] ?? 'your bank';
    }
}
