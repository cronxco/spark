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
}
