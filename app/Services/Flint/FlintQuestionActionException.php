<?php

namespace App\Services\Flint;

use RuntimeException;

class FlintQuestionActionException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}
