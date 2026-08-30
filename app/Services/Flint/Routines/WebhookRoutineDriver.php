<?php

namespace App\Services\Flint\Routines;

use App\Models\User;
use App\Services\Flint\RoutineConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hands the routine to a Claude Code Routine over its webhook. This is the
 * behaviour Spark has always had, and remains the default.
 */
class WebhookRoutineDriver implements RoutineDriver
{
    public function run(User $user, string $routine, array $payload): RoutineResult
    {
        $url = RoutineConfig::url($routine);

        if (empty($url)) {
            Log::info('Flint routine webhook URL not configured; skipping trigger', [
                'user_id' => $user->id,
                'routine' => $routine,
            ]);

            return RoutineResult::notApplicable('Webhook URL is not configured.');
        }

        $request = Http::withSentryTracing()->asJson()->timeout(20)->connectTimeout(5);

        if ($secret = RoutineConfig::secret($routine)) {
            $request = $request->withToken($secret);
        }

        $response = $request->post($url, $payload);

        if (! $response->successful()) {
            Log::error('Flint routine webhook returned a non-2xx response', [
                'user_id' => $user->id,
                'routine' => $routine,
                'status' => $response->status(),
            ]);

            $response->throw();
        }

        return RoutineResult::success(['driver' => 'webhook']);
    }
}
