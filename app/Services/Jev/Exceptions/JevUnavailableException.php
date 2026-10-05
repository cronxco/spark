<?php

namespace App\Services\Jev\Exceptions;

use RuntimeException;

/**
 * Jev could not be reached in time: not configured, network failure, rate
 * limited, overloaded or a server error. Callers fall back to deterministic
 * behaviour; this is an expected, unreported condition.
 */
class JevUnavailableException extends RuntimeException {}
