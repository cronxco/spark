<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A task ran but did not produce the output it promises, such as an image
 * task that could not download any image. The run is recorded as failed, and
 * dependent tasks are not started, but it is an expected outcome rather than
 * a bug: it is not reported to Sentry, not retried by the queue, and not
 * thrown to whoever dispatched the task. Reprocessing runs it again.
 */
class TaskOutcomeException extends RuntimeException
{
    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        string $message,
        public readonly string $outcome = 'failed',
        public readonly array $counts = [],
    ) {
        parent::__construct($message);
    }
}
