<?php

namespace App\Services\Knowledge;

use App\Models\User;

final class KnowledgeFeature
{
    public static function enabled(User $user, string $feature): bool
    {
        return (bool) data_get($user->settings, 'knowledge_features.' . $feature, config('knowledge.features.' . $feature, true));
    }

    public static function ensure(User $user, string $feature): void
    {
        abort_unless(self::enabled($user, $feature), 404);
    }
}
