<?php

namespace App\Services\Actions;

use RuntimeException;

class ActionFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $failureCode = 'failed')
    {
        parent::__construct($message);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what} not found.", 'not_found');
    }
}
