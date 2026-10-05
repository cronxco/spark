<?php

namespace App\Notifications;

use App\Models\EventObject;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class DailyDigestReady extends SparkNotification
{
    public function __construct(
        public ?EventObject $digestObject,
        public string $period,
        public ?string $title = null,
        public ?string $summary = null,
        public int $unansweredQuestionCount = 0,
    ) {}

    public function getNotificationType(): string
    {
        return 'daily_digest';
    }

    public function getTitle(): string
    {
        return $this->title ?? $this->digestObject?->title ?? $this->getTimeBasedGreeting() . ' digest';
    }

    public function getMessage(): string
    {
        return $this->headline() ?? 'Your daily digest is ready to review.';
    }

    public function getActionUrl(): ?string
    {
        return $this->digestUrl();
    }

    public function getEntityType(): ?string
    {
        return $this->digestObject === null ? null : 'object';
    }

    public function getEntityId(): ?string
    {
        return $this->digestObject?->id === null ? null : (string) $this->digestObject->id;
    }

    public function getGroupKey(): ?string
    {
        return $this->digestObject === null
            ? null
            : "daily_digest:{$this->digestObject->id}";
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

    public function toArray(User $notifiable): array
    {
        return [
            ...parent::toArray($notifiable),
            'digest_object_id' => $this->digestObject?->id,
            'period' => $this->period,
            'headline' => $this->headline(),
            'unanswered_question_count' => $this->unansweredQuestionCount,
        ];
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
