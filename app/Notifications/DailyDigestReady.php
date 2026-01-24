<?php

namespace App\Notifications;

use App\Models\EventObject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

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

        if ($notifiable->hasPushNotificationsEnabled() && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $headline = $this->findBlockContent('flint_summarised_headline');
        $keyPoints = $this->findBlockMetadata('flint_five_key_points', 'points') ?? [];

        $message = (new MailMessage)
            ->subject('Your '.ucfirst($this->period).' Digest is Ready')
            ->greeting('Hello!')
            ->line($headline ?? 'Your daily digest is ready to review.');

        if (! empty($keyPoints)) {
            $message->line('**Key Points:**');
            foreach (array_slice($keyPoints, 0, 3) as $point) {
                $message->line('• '.$point);
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

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $headline = $this->findBlockContent('flint_summarised_headline');
        $greeting = $this->getTimeBasedGreeting();
        $body = $headline ? $this->toSentenceCase($headline) : 'Your daily digest is ready to review.';

        return (new WebPushMessage)
            ->title($greeting)
            ->icon('/icons/Spark-iOS-Default-60x60@3x.png')
            ->body($body)
            ->badge('/favicon.ico')
            ->tag('daily-digest-'.$this->period)
            ->data([
                'url' => route('objects.show', $this->digestObject->id),
                'type' => 'daily_digest',
                'digest_object_id' => $this->digestObject->id,
                'period' => $this->period,
            ])
            ->options([
                'TTL' => 86400, // 24 hours
                'urgency' => 'normal',
            ]);
    }

    private function getTimeBasedGreeting(): string
    {
        $hour = now()->hour;

        if ($hour < 12) {
            return 'Good Morning';
        }

        if ($hour < 19) {
            return 'Good Afternoon';
        }

        return 'Good Evening';
    }

    private function toSentenceCase(string $text): string
    {
        $text = mb_strtolower($text);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
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
