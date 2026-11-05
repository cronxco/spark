<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\NotificationCatalogue;
use App\Notifications\NotificationDigest;
use App\Services\EffectiveTimezoneResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendNotificationDigests extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send-digests';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email each Daily Digest user the notifications from the day before their digest time';

    /**
     * Execute the console command.
     *
     * Each user's digest covers the 24 hours up to today's digest time in
     * their effective timezone. The window is fixed, so a late or repeated run
     * neither skips nor repeats an item, and the cache marker stops a second
     * email for the same day.
     */
    public function handle(EffectiveTimezoneResolver $resolver): int
    {
        $sent = 0;

        User::query()
            ->where('settings->notifications->delayed_sending->mode', 'daily_digest')
            ->each(function (User $user) use ($resolver, &$sent) {
                $timezone = $resolver->timezoneFor($user);
                $now = now()->timezone($timezone);
                $windowEnd = $now->copy()->setTimeFromTimeString($user->getDigestTime());

                if ($now->lt($windowEnd)) {
                    return;
                }

                $marker = "notifications:digest:{$user->id}:{$windowEnd->toDateString()}";

                if (! Cache::add($marker, true, now()->addDays(3))) {
                    return;
                }

                try {
                    $items = $this->itemsFor($user, $windowEnd->copy()->subDay()->utc(), $windowEnd->copy()->utc());

                    if ($items === []) {
                        return;
                    }

                    $user->notify(new NotificationDigest($items));
                } catch (Throwable $exception) {
                    Cache::forget($marker);

                    throw $exception;
                }
                $sent++;
            });

        $this->info("Sent {$sent} notification digest(s).");

        return self::SUCCESS;
    }

    /**
     * The digest lines for notifications that occurred in the window and that
     * the user wants by email. Priority types are left out: they were already
     * emailed immediately.
     *
     * @return array<int, array{title: string, body: ?string}>
     */
    private function itemsFor(User $user, Carbon $from, Carbon $to): array
    {
        return $user->notifications()
            ->where('updated_at', '>', $from)
            ->reorder('created_at')
            ->get()
            ->filter(function (DatabaseNotification $notification) use ($user, $from, $to) {
                $type = $notification->data['type'] ?? null;
                $occurredAt = isset($notification->data['last_occurred_at'])
                    ? Carbon::parse($notification->data['last_occurred_at'])
                    : $notification->created_at;

                return $type !== null
                    && $occurredAt->gt($from)
                    && $occurredAt->lte($to)
                    && ! NotificationCatalogue::forcesDelivery($type)
                    && $user->hasEmailNotificationsEnabled($type);
            })
            ->map(fn (DatabaseNotification $notification) => [
                'title' => (string) ($notification->data['title'] ?? 'Notification'),
                'body' => $notification->data['body'] ?? $notification->data['message'] ?? null,
            ])
            ->values()
            ->all();
    }
}
