<?php

namespace App\Services\IntegrationRuns;

use Closure;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;

class RunBatchMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        $dispatcher = app(Dispatcher::class);

        if ($job->batchId === null || ! $dispatcher instanceof QueueingDispatcher) {
            return $next($job);
        }

        app()->instance(Dispatcher::class, new RunBatchDispatcher($dispatcher, app(BatchRepository::class), $job->batchId));

        try {
            return $next($job);
        } finally {
            app()->instance(Dispatcher::class, $dispatcher);
        }
    }
}
