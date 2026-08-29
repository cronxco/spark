<?php

namespace App\Mcp\Concerns;

use App\Support\SparkAbility;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresSparkAbility
{
    protected function requireAbility(Request $request, string $ability): ?Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        if (! SparkAbility::allows($user, $ability)) {
            return Response::error("Token lacks required capability: {$ability}.");
        }

        return null;
    }
}
