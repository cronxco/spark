<?php

namespace App\Notifications;

use App\Models\EventObject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyDigestReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EventObject $digestObject,
        public string $period,
        public array $blocks
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->hasEmailNotificationsEnabled('daily_digest')) {
            $channels[] = 'mail';
        }

        if ($notifiable->hasPushNotificationsEnabled()) {
            $channels[] = 'push';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $headline = $this->findBlockContent('flint_summarised_headline');
        $keyPoints = $this->findBlockMetadata('flint_five_key_points', 'points') ?? [];

        $message = (new MailMessage)
            ->subject('Your ' . ucfirst($this->period) . ' Digest is Ready')
            ->greeting('Hello!')
            ->line($headline ?? 'Your daily digest is ready to review.');

        if (! empty($keyPoints)) {
            $message->line('**Key Points:**');
            foreach (array_slice($keyPoints, 0, 3) as $point) {
                $message->line('• ' . $point);
            }
        }

        $message->action('View Full Digest', route('objects.show', $this->digestObject->id));

        return $message;
    }

    public function toArray($notifiable): array
    {
        return [
            'digest_object_id' => $this->digestObject->id,
            'period' => $this->period,
            'title' => $this->digestObject->title,
            'headline' => $this->findBlockContent('flint_summarised_headline'),
        ];
    }

    public function toPush($notifiable): array
    {
        $headline = $this->findBlockContent('flint_summarised_headline');

        return [
            'title' => ucfirst($this->period) . ' Digest Ready',
            'body' => $headline ?? 'Your daily digest is ready to review.',
            'data' => [
                'digest_object_id' => $this->digestObject->id,
                'period' => $this->period,
            ],
        ];
    }

    private function findBlockContent(string $blockType): ?string
    {
        $block = collect($this->blocks)->firstWhere('block_type', $blockType);

        return $block?->metadata['content'] ?? null;
    }

    private function findBlockMetadata(string $blockType, string $key): mixed
    {
        $block = collect($this->blocks)->firstWhere('block_type', $blockType);

        return $block?->metadata[$key] ?? null;
    }
}
