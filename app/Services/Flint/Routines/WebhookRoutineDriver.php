<?php

namespace App\Services\Flint\Routines;

use App\Models\ActionProgress;
use App\Models\User;
use App\Services\Flint\RoutineConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Hands the routine to a Claude Code Routine over its fire endpoint. This is
 * the behaviour Spark has always had, and remains the default.
 *
 * A Routine fires the prompt it was configured with; it does not read the
 * request body as instructions. The only channel back into the session is the
 * endpoint's `text` field, which is appended to the run as an extra turn — so
 * the trigger payload is serialised into that field rather than posted as the
 * body. Posting the payload as the body (which is what Spark did until this
 * was fixed) is accepted with a 2xx and silently discarded, leaving the skill
 * to infer the local date and period from the wall clock and leaving
 * `create-flint-digest` without the `run_token` that makes a retry idempotent.
 */
class WebhookRoutineDriver implements RoutineDriver
{
    /**
     * The beta opting this account into the Routine fire endpoint. Without it
     * the endpoint 404s; without `anthropic-version` it 400s (see #1059).
     */
    private const BETA = 'experimental-cc-routine-2026-04-01';

    /**
     * The extra turn appended to the Routine's own prompt.
     *
     * The skills document this exact JSON shape in their "Read the payload"
     * step, so it is sent verbatim under a one-line label rather than being
     * reworded into prose.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private static function turn(array $payload): string
    {
        return "Flint routine trigger payload. Use these values rather than inferring them:\n\n"
            . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function run(User $user, string $routine, array $payload, ?ActionProgress $progress = null): RoutineResult
    {
        $url = RoutineConfig::url($routine);

        if (empty($url)) {
            Log::info('Flint routine webhook URL not configured; skipping trigger', [
                'user_id' => $user->id,
                'routine' => $routine,
            ]);

            return RoutineResult::notApplicable('Webhook URL is not configured.');
        }

        $request = Http::withSentryTracing()
            ->asJson()
            ->withHeaders([
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => self::BETA,
            ])
            ->timeout(20)
            ->connectTimeout(5);

        if ($secret = RoutineConfig::secret($routine)) {
            $request = $request->withToken($secret);
        }

        $response = $request->post($url, ['text' => self::turn($payload)]);

        if (! $response->successful()) {
            Log::error('Flint routine fire endpoint returned a non-2xx response', [
                'user_id' => $user->id,
                'routine' => $routine,
                'status' => $response->status(),
            ]);

            $response->throw();
        }

        return RoutineResult::success(['driver' => 'webhook']);
    }
}
