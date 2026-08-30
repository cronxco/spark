<?php

namespace App\Services\Flint;

/**
 * Resolves the webhook configuration for a Flint routine.
 *
 * Each routine owns its own endpoint and bearer secret, so a leaked secret
 * authenticates against one routine rather than all four and secrets can be
 * rotated one at a time. A routine with no secret of its own falls back to the
 * shared one, which is what keeps the rollout free of a flag day.
 */
class RoutineConfig
{
    /**
     * Every routine Spark can fire, including the digest.
     *
     * @var array<int, string>
     */
    public const ROUTINES = ['digest', 'topics', 'reading_list', 'news_roundup'];

    public static function isKnown(string $routine): bool
    {
        return in_array($routine, self::ROUTINES, true);
    }

    /**
     * The routine's webhook endpoint, or null when it isn't configured.
     */
    public static function url(string $routine): ?string
    {
        $url = config("services.flint_routine.routines.{$routine}.url");

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The routine's bearer secret, falling back to the shared secret.
     */
    public static function secret(string $routine): ?string
    {
        $secret = config("services.flint_routine.routines.{$routine}.secret");

        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        $shared = config('services.flint_routine.secret');

        return is_string($shared) && $shared !== '' ? $shared : null;
    }
}
