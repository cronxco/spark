<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class DataExportReady extends SparkNotification
{
    public function __construct(
        public string $exportType,
        public string $downloadUrl,
        public ?array $details = null
    ) {}

    public function getNotificationType(): string
    {
        return 'data_export_ready';
    }

    public function getIcon(): string
    {
        return 'fas-download';
    }

    public function getColor(): string
    {
        return 'info';
    }

    public function getTitle(): string
    {
        return 'Data Export Ready';
    }

    public function getMessage(): string
    {
        return "Your {$this->exportType} export is ready for download.";
    }

    public function getActionUrl(): ?string
    {
        return $this->downloadUrl;
    }

    /**
     * Get the mail representation of the notification
     */
    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Data Export Ready: {$this->exportType}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your {$this->exportType} export is ready for download.")
            ->action('Download Export', $this->downloadUrl);

        if ($this->details) {
            $mail->line('Export Details:');
            foreach ($this->details as $key => $value) {
                $mail->line(ucfirst(str_replace('_', ' ', $key)) . ': ' . $value);
            }
        }

        $mail->line('This download link will expire in 7 days.');

        return $mail;
    }
}
