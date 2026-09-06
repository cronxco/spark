<?php

namespace App\Support;

use App\Models\User;

/**
 * Capability checks shared by REST and MCP surfaces.
 *
 * `mcp:read` is accepted as a transition capability for existing MCP tokens.
 * New callers should request the capability named by the operation instead.
 */
final class SparkAbility
{
    /** @var array<string, array<int, string>> */
    private const LEGACY_ALIASES = [
        'data:read' => ['mcp:read'],
        'insights:read' => ['mcp:read'],
        'integrations:read' => ['mcp:read'],
        'flint:read' => ['mcp:read'],
    ];

    /**
     * Every capability a personal access token may be issued with.
     *
     * Authority is attenuating: a token can only ever be created with
     * capabilities drawn from this list, and never with more than the
     * credential creating it already holds. Wildcard is deliberately absent —
     * `*` satisfies every `tokenCan()` check in the application, so it is not
     * a capability but an escape from the capability system.
     *
     * @var array<int, string>
     */
    public const DELEGABLE = [
        'bookmark:write',
        'data:image',
        'data:read',
        'data:write',
        'finance:read',
        'finance:write',
        'flint:read',
        'flint:run',
        'flint:write',
        'insights:read',
        'insights:write',
        'integrations:read',
        'integrations:sync',
        'tokens:manage',
    ];

    /**
     * Capabilities that exist but may never be delegated into a new token.
     *
     * `ios:read`/`ios:write` are the iOS app's own OAuth session scopes, and
     * `mcp:read` is a transition alias we do not want to keep issuing.
     *
     * @var array<int, string>
     */
    public const NON_DELEGABLE = ['ios:read', 'ios:write', 'mcp:read'];

    public static function allows(User $user, string $ability): bool
    {
        // Public API and MCP calls must carry a personal access token. Keep
        // Laravel MCP's in-process test helper ergonomic without making a
        // session-authenticated production request silently all-powerful.
        if ($user->currentAccessToken() === null) {
            return app()->environment('testing');
        }

        if ($user->tokenCan($ability)) {
            return true;
        }

        foreach (self::LEGACY_ALIASES[$ability] ?? [] as $legacyAbility) {
            if ($user->tokenCan($legacyAbility)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the issuing credential may mint a token carrying every one of
     * the requested capabilities.
     *
     * A session-authenticated request (no access token — the web settings UI)
     * acts with the user's full authority and may delegate anything on the
     * allowlist. A token-authenticated request may only delegate a subset of
     * what it already holds, so a stolen narrow token cannot widen itself.
     *
     * @param  array<int, string>  $requested
     */
    public static function canDelegate(User $issuer, array $requested): bool
    {
        if (array_diff($requested, self::DELEGABLE) !== []) {
            return false;
        }

        $issuingToken = $issuer->currentAccessToken();

        if ($issuingToken === null) {
            return true;
        }

        // A wildcard token is not treated as holding everything. Wildcards are
        // legacy credentials being retired; letting one mint fresh scoped tokens
        // would launder that authority forward indefinitely.
        $held = $issuingToken->abilities;

        if (is_array($held) && in_array('*', $held, true)) {
            return false;
        }

        // tokenCan() rather than a direct array_diff, because Sanctum's testing
        // helper substitutes a mock token whose `abilities` property is not a
        // real array while `can()` still answers correctly.
        foreach ($requested as $ability) {
            if (! $issuer->tokenCan($ability)) {
                return false;
            }
        }

        return true;
    }
}
