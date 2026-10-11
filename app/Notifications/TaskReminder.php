<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Knowledge\KnowledgeFeature;
use App\Services\Knowledge\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

class TaskReminder extends SparkNotification
{
    public function __construct(public string $taskId, public string $remindAt, public string $taskTitle) {}

    public function getNotificationType(): string
    {
        return 'task_reminder';
    }

    public function getTitle(): string
    {
        return $this->taskTitle;
    }

    public function getMessage(): string
    {
        return 'You asked Spark to remind you about this task.';
    }

    public function getIcon(): string
    {
        return 'fas.list-check';
    }

    public function getColor(): string
    {
        return 'info';
    }

    public function getActionUrl(): ?string
    {
        return route('knowledge.task', $this->taskId);
    }

    public function getDeepLink(): ?string
    {
        return 'task:'.$this->taskId;
    }

    public function getGroupKey(): ?string
    {
        return 'task_reminder:'.$this->taskId.':'.$this->remindAt;
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        if (! KnowledgeFeature::enabled($notifiable, 'reminders')) {
            return false;
        }
        $block = app(TaskService::class)->query($notifiable)->find($this->taskId);
        if (! $block) {
            return false;
        }
        $task = app(TaskService::class)->payload($block);

        return $task['status'] === 'open' && $task['remind_at'] === $this->remindAt && CarbonImmutable::parse($this->remindAt)->isPast() && parent::shouldSend($notifiable, $channel);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->taskTitle)->line($this->getMessage())->action('Open task', $this->getActionUrl());
    }
}
