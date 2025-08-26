<?php

namespace App\Providers;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Event;
/** @phpstan-ignore-next-line */
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\Tracing\SpanContext;
use SocialiteProviders\Authelia\Provider as AutheliaProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        // Register the Authelia socialite provider
        // This allows the use of Authelia for authentication via Socialite
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('authelia', AutheliaProvider::class);
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
