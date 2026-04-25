<?php

namespace App\Support;

/**
 * PKCE (Proof Key for Code Exchange) helpers for OAuth 2.0 authorization code flow.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636
 */
class Pkce
{
    public const METHOD_S256 = 'S256';

    public const METHOD_PLAIN = 'plain';

    /**
     * Generate a random PKCE code verifier (43–128 chars, URL-safe base64).
     */
    public static function generateCodeVerifier(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    /**
     * Derive a code challenge from a verifier using the S256 method.
     */
    public static function generateCodeChallenge(string $codeVerifier): string
    {
        return self::base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    /**
     * Verify a verifier against a stored challenge using the S256 method.
     *
     * Only S256 is accepted — plain is rejected to prevent downgrade attacks.
     */
    public static function verifyChallenge(string $verifier, string $challenge, string $method = self::METHOD_S256): bool
    {
        if ($method !== self::METHOD_S256) {
            return false;
        }

        return hash_equals($challenge, self::generateCodeChallenge($verifier));
    }

    protected static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
