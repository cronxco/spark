<?php

namespace App\Services\Jev\Exceptions;

use RuntimeException;

/**
 * Jev answered, but not with something Spark can trust: a client error, a
 * missing or mistyped answer, an option that was never offered, probabilities
 * out of range, or an unexpected model version. Callers fall back to
 * deterministic behaviour and report it.
 */
class JevResponseException extends RuntimeException {}
