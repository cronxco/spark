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

    public const SKILLS = [
        'digest' => 'spark-day-briefing-async',
        'topics' => 'flint-topics',
        'reading_list' => 'flint-reading-list',
        'news_roundup' => 'flint-news-roundup',
    ];

    /**
     * The kind of digest each routine produces, for clients that lay out a
     * news roundup differently from a briefing.
     *
     * `topics` writes no digest, so it has no kind.
     *
     * @var array<string, string>
     */
    public const DIGEST_KINDS = [
        'digest' => 'briefing',
        'reading_list' => 'reading_list',
        'news_roundup' => 'news_roundup',
    ];

    /**
     * The digest kind for a routine, or null when the routine is unknown or
     * writes no digest.
     */
    public static function digestKind(?string $routine): ?string
    {
        return $routine === null ? null : (self::DIGEST_KINDS[$routine] ?? null);
    }

    public static function isKnown(string $routine): bool
    {
        return in_array($routine, self::ROUTINES, true);
    }

    public static function canonicalSkill(string $name): ?string
    {
        if (isset(self::SKILLS[$name])) {
            return self::SKILLS[$name];
        }

        return in_array($name, self::SKILLS, true) ? $name : null;
    }

    public static function routineFor(string $name): ?string
    {
        $skill = self::canonicalSkill($name);

        return $skill ? array_search($skill, self::SKILLS, true) ?: null : null;
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
