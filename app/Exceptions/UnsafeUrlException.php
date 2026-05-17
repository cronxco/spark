<?php

namespace App\Exceptions;

use Exception;

class UnsafeUrlException extends Exception
{
    public function __construct(
        protected string $unsafeUrl,
        string $reason = 'URL is not allowed'
    ) {
        parent::__construct("Unsafe URL rejected ({$reason}): {$unsafeUrl}");
    }

    public function getUnsafeUrl(): string
    {
        return $this->unsafeUrl;
    }
}
