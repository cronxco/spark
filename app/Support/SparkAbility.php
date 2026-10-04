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
        'integrations:manage',
        'integrations:read',
        'integrations:sync',
        'notifications:read',
        'notifications:write',
        'tokens:manage',
    ];

    /**
     * Capabilities that exist but may never be delegated into a new token.
     *
     * `mobile:session` marks the iOS app's own OAuth session token, and
     * `tokens:revoke` lets that session revoke the user's other tokens.
     * `ios:read`/`ios:write` are the session scopes it replaced (decision
     * D-API-3); they no longer open any route. `mcp:read` is a transition
     * alias we do not want to keep issuing.
     *
     * @var array<int, string>
     */
    public const NON_DELEGABLE = ['mobile:session', 'tokens:revoke', 'ios:read', 'ios:write', 'mcp:read'];

    /** The marker on every iOS app session token. */
    public const MOBILE_SESSION_MARKER = 'mobile:session';

    /** The retired iOS session scopes. A token holding only these must sign in again. */
    public const LEGACY_MOBILE_ABILITIES = ['ios:read', 'ios:write'];

    /**
     * What a read-only iOS session may do.
     *
     * @var array<int, string>
     */
    public const MOBILE_READ = [
        self::MOBILE_SESSION_MARKER,
        'data:read',
        'finance:read',
        'flint:read',
        'insights:read',
        'integrations:read',
        'notifications:read',
    ];

    /**
     * What an iOS session may change, on top of MOBILE_READ. Token creation
     * (`tokens:manage`) is deliberately absent, so a stolen app session cannot
     * mint a longer-lived credential for itself.
     *
     * @var array<int, string>
     */
    public const MOBILE_WRITE = [
        'data:write',
        'finance:write',
        'flint:write',
        'insights:write',
        'integrations:manage',
        'integrations:sync',
        'notifications:write',
        'tokens:revoke',
    ];

    /**
     * The full iOS app session.
     *
     * @var array<int, string>
     */
    public const MOBILE_SESSION = [...self::MOBILE_READ, ...self::MOBILE_WRITE];

    /** @var array<string, array<int, string>> */
    private const LEGACY_ALIASES = [
        'data:read' => ['mcp:read'],
        'insights:read' => ['mcp:read'],
        'integrations:read' => ['mcp:read'],
        'flint:read' => ['mcp:read'],
    ];

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
        $storedToken = $issuingToken->getKey() === null
            ? null
            : $issuer->tokens()->whereKey($issuingToken->getKey())->first();
        $held = $storedToken?->abilities;

        if (is_array($held) && in_array('*', $held, true)) {
            return false;
        }

        // tokenCan() rather than a direct array_diff also keeps Sanctum's
        // actingAs() test token usable without reading properties from it.
        foreach ($requested as $ability) {
            if (! $issuer->tokenCan($ability)) {
                return false;
            }
        }

        return true;
    }
}
