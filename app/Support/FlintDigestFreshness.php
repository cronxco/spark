<?php

namespace App\Support;

use Carbon\CarbonInterface;

class FlintDigestFreshness
{
    /** @return array{state:string,age_seconds:int} */
    public static function for(CarbonInterface $generatedAt): array
    {
        $age = max(0, (int) $generatedAt->diffInSeconds(now(), false));
        $threshold = (int) config('services.flint_routine.freshness_seconds', 86400);

        return [
            'state' => $age <= $threshold ? 'fresh' : 'stale',
            'age_seconds' => $age,
        ];
    }
}
