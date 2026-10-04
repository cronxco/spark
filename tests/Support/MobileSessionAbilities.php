<?php

namespace Tests\Support;

/** Sessions usable before and after the mobile capability cutover. */
final class MobileSessionAbilities
{
    public static function with(array $abilities): array
    {
        if (in_array('ios:read', $abilities, true)) {
            $abilities = [...$abilities, 'mobile:session', 'data:read', 'finance:read', 'flint:read', 'insights:read', 'integrations:read', 'notifications:read'];
        }

        if (in_array('ios:write', $abilities, true)) {
            $abilities = [...$abilities, 'mobile:session', 'data:write', 'finance:write', 'flint:write', 'insights:write', 'integrations:manage', 'integrations:sync', 'notifications:write', 'tokens:revoke'];
        }

        return array_values(array_unique($abilities));
    }
}
