<?php

namespace App\Notifications;

use App\Models\EventObject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class DailyDigestReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?EventObject $digestObject,
        public string $period,
        public ?string $title = null,
        public ?string $summary = null,
        public int $unansweredQuestionCount = 0,
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->hasEmailNotificationsEnabled('daily_digest')) {
            $channels[] = 'mail';
        }

        if ($notifiable->hasPushNotificationsEnabled() && $notifiable->pushSubscriptions()->validWebPush()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title ?? 'Your ' . ucfirst($this->period) . ' Digest is Ready')
            ->greeting('Hello!')
            ->line($this->summary ?? 'Your daily digest is ready to review.');

        if ($this->unansweredQuestionCount > 0) {
            $message->line(sprintf(
                '**%d question%s waiting for you.**',
                $this->unansweredQuestionCount,
                $this->unansweredQuestionCount === 1 ? '' : 's',
            ));
        }

        $message->action('View Full Digest', $this->digestUrl());

        return $message;
    }

    public function toArray($notifiable): array
    {
        return [
            'digest_object_id' => $this->digestObject?->id,
            'period' => $this->period,
            'title' => $this->title ?? $this->digestObject?->title,
            'headline' => $this->headline(),
            'unanswered_question_count' => $this->unansweredQuestionCount,
        ];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title ?? $this->getTimeBasedGreeting())
            ->icon('/icons/Spark-iOS-Default-60x60@3x.png')
            ->body($this->headline() ?? 'Your daily digest is ready to review.')
            ->badge('/favicon.ico')
            ->tag('daily-digest-' . $this->period)
            ->data([
                'url' => $this->digestUrl(),
                'type' => 'daily_digest',
                'digest_object_id' => $this->digestObject?->id,
                'period' => $this->period,
            ])
            ->options([
                'TTL' => 86400, // 24 hours
                'urgency' => 'normal',
            ]);
    }

    /**
     * A one-line teaser: the opening sentence of the digest summary, short
     * enough to survive a push notification.
     */
    private function headline(): ?string
    {
        if (blank($this->summary)) {
            return null;
        }

        $firstLine = trim(Str::before(trim($this->summary), "\n"));

        // `before('. ')` returns the whole line when there is no sentence break,
        // so the result may already carry its own terminal punctuation.
        $firstSentence = Str::of($firstLine)->before('. ')->trim()->toString();

        if ($firstSentence === '') {
            return Str::limit($firstLine, 160);
        }

        if (! Str::endsWith($firstSentence, ['.', '!', '?'])) {
            $firstSentence .= '.';
        }

        return Str::limit($firstSentence, 160);
    }

    private function digestUrl(): string
    {
        return $this->digestObject
            ? route('objects.show', $this->digestObject->id)
            : route('flint.index');
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
}
