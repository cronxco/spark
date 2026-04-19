<?php

namespace App\Providers;

use App\Events\Mobile\NewEventBroadcast;
use App\Events\Mobile\NotificationReceived;
use App\Jobs\Data\Receipt\FindReceiptForTransactionJob;
use App\Models\Block;
use App\Models\Event as EventModel;
use App\Models\EventObject;
use App\Notifications\SparkNotification;
use App\Observers\BlockObserver;
use App\Observers\EventObjectObserver;
use App\Observers\EventObserver;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
/** @phpstan-ignore-next-line */
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\Tracing\SpanContext;
use SocialiteProviders\Authelia\Provider as AutheliaProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers for automatic embedding generation
        EventModel::observe(EventObserver::class);
        Block::observe(BlockObserver::class);
        EventObject::observe(EventObjectObserver::class);

        // Force HTTPS in development
        URL::forceScheme('https');

        // Register the Authelia socialite provider
        // This allows the use of Authelia for authentication via Socialite
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('authelia', AutheliaProvider::class);
        });

        // Receipt reverse matching: When a transaction is created, look for matching receipts
        // Skip during testing to avoid cascading errors with sync queue
        EventModel::created(function (EventModel $event) {
            if (app()->runningUnitTests()) {
                return;
            }

            if (in_array($event->service, ['monzo', 'gocardless'])
                && $event->domain === 'money'
                && in_array($event->action, [
                    'card_payment_to', 'payment_to', 'made_transaction',
                    'card_refund_from', 'payment_from',
                ])) {
                FindReceiptForTransactionJob::dispatch($event);
            }
        });

        // iOS broadcast on new Event creation. Throttled per-user via Redis to avoid
        // flooding subscribers when bulk ingestion creates many events in quick succession.
        // The 2-second SETNX window means we only emit one ping per user per burst —
        // the iOS client then re-fetches the feed delta on its own schedule.
        // If Redis is unreachable we fail open (broadcast) rather than silently dropping;
        // the broadcast itself uses the configured driver (log in tests) and is a no-op
        // when nothing is subscribed.
        EventModel::created(function (EventModel $event) {
            $userId = $event->integration?->user_id;
            if (! $userId) {
                return;
            }

            try {
                $lockAcquired = (bool) Redis::set("broadcast:newevent:{$userId}", '1', 'EX', 2, 'NX');
            } catch (Throwable) {
                $lockAcquired = true;
            }

            if (! $lockAcquired) {
                return;
            }

            event(NewEventBroadcast::fromEvent($event, (string) $userId));
        });

        // iOS broadcast on database notification persistence. Listens for the
        // built-in NotificationSent event with channel='database' so the inbox
        // mirror in the app updates in real time alongside the database insert.
        Event::listen(function (NotificationSent $event) {
            if ($event->channel !== 'database') {
                return;
            }

            if (! $event->notification instanceof SparkNotification) {
                return;
            }

            $notifiable = $event->notifiable;
            if (! $notifiable || ! method_exists($notifiable, 'getKey')) {
                return;
            }

            $payload = $event->notification->toArray($notifiable);

            event(new NotificationReceived(
                userId: (string) $notifiable->getKey(),
                notificationId: (string) ($event->notification->id ?? ''),
                type: (string) ($payload['type'] ?? $event->notification->getNotificationType()),
                title: $payload['title'] ?? null,
                body: $payload['message'] ?? null,
                deepLink: $payload['action_url'] ?? null,
            ));
        });

        // Sentry tracing for outgoing HTTP requests via Laravel Http client
        Http::macro('withSentryTracing', function () {
            return Http::beforeSending(function ($request, $options) {
                $hub = SentrySdk::getCurrentHub();
                $parentSpan = $hub->getSpan();
                if ($parentSpan) {
                    $spanContext = new SpanContext;
                    $spanContext->setOp('http.client');
                    $spanContext->setDescription($request->getMethod() . ' ' . $request->getUri());
                    $span = $parentSpan->startChild($spanContext);

                    // finish span after response
                    $options['on_stats'] = function ($stats) use ($span) {
                        $span->setData(['transfer_time_ms' => $stats->getTransferTime() * 1000]);
                        $span->finish();
                    };
                }

                return $request;
            });
        });

        // Note: Sentry breadcrumb functionality requires proper method availability
        // Consider using middleware or response events for HTTP logging

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            // Track success or failure of scheduled tasks
            $context = [
                'task_class' => get_class($event->task),
                'description' => $event->task->description ?? 'No description',
                'expression' => $event->task->expression ?? 'No expression',
                'exit_code' => $event->task->exitCode,
                'connection' => $event->task->onConnection ?? 'default',
                'queue' => $event->task->onQueue ?? 'default',
                'mutex_name' => method_exists($event->task, 'mutexName') ? $event->task->mutexName() : 'No mutex',
            ];

            if ($event->task->exitCode === 0) {
                \Sentry\captureMessage('Scheduled task completed', Severity::info(), EventHint::fromArray(['extra' => $context]));
            } else {
                \Sentry\captureMessage('Scheduled task finished with non-zero exit code', Severity::warning(), EventHint::fromArray(['extra' => $context]));
            }
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            \Sentry\captureException($event->exception);
        });

        if ($this->app->environment('local')) {
            Horizon::auth(function ($request) {
                return true;
            });
        }
    }
}
