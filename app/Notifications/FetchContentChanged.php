<?php

namespace App\Notifications;

use App\Models\EventObject;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class FetchContentChanged extends SparkNotification
{
    public function __construct(
        public EventObject $webpage,
        public string $previousHash,
        public string $currentHash
    ) {}

    public function getNotificationType(): string
    {
        return 'fetch_content_changed';
    }

    public function isPriority(): bool
    {
        return false; // Not a priority notification
    }

    public function getIcon(): string
    {
        return 'o-pencil-square';
    }

    public function getColor(): string
    {
        return 'info';
    }

    public function getTitle(): string
    {
        return 'Content Updated';
    }

    public function getMessage(): string
    {
        $title = $this->webpage->title;
        $domain = $this->webpage->metadata['domain'] ?? parse_url($this->webpage->url, PHP_URL_HOST);

        return "Content has been updated: {$title} ({$domain})";
    }

    public function getActionUrl(): ?string
    {
        return route('objects.show', $this->webpage->id);
    }

    /**
     * Get the mail representation of the notification
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Content Updated: '.$this->webpage->title)
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->getMessage())
            ->line('The content has changed since the last fetch.')
            ->action('View Content', $this->getActionUrl())
            ->line('You can view the new content and AI-generated summaries.');
    }
}
