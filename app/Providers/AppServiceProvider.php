<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

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
        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('authelia', \SocialiteProviders\Authelia\Provider::class);
        });

        // Sentry tracing for outgoing HTTP requests via Laravel Http client
        Http::macro('withSentryTracing', function () {
            return Http::beforeSending(function ($request, $options) {
                $hub = SentrySdk::getCurrentHub();
                $parentSpan = $hub->getSpan();
                if ($parentSpan) {
                    $spanContext = new SpanContext();
                    $spanContext->setOp('http.client');
                    $spanContext->setDescription($request->getMethod().' '.$request->getUri());
                    $span = $parentSpan->startChild($spanContext);

                    // finish span after response
                    $options['on_stats'] = function ($stats) use ($span) {
                        $span->setData('transfer_time_ms', $stats->getTransferTime() * 1000);
                        $span->finish();
                    };
                }
                return $request;
            });
        });

        // Always apply beforeSending for breadcrumbs of responses
        Http::beforeSending(function ($request, $options) {
            \Sentry\addBreadcrumb(new \Sentry\Breadcrumb(\Sentry\Breadcrumb::LEVEL_INFO, \Sentry\Breadcrumb::TYPE_HTTP, 'http', sprintf('%s %s', $request->getMethod(), (string) $request->getUri())));
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            // Track success or failure of scheduled tasks
            $context = [
                'task' => (string) $event->task,
                'description' => $event->task->description,
                'expression' => $event->task->expression,
                'exit_code' => $event->task->exitCode,
                'connection' => $event->task->onConnection,
                'queue' => $event->task->onQueue,
                'mutex_name' => $event->task->mutexName(),
            ];

            if ($event->task->exitCode === 0) {
                \Sentry\captureMessage('Scheduled task completed', \Sentry\EventHint::fromArray(['extra' => $context]));
            } else {
                \Sentry\captureMessage('Scheduled task finished with non-zero exit code', \Sentry\EventHint::fromArray(['extra' => $context]));
            }
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            \Sentry\captureException($event->exception);
        });
    }
}
