<?php

namespace App\Jobs\Flint;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fires the outbound webhook for a once-daily Flint routine that isn't the
 * digest — topic review, reading-list curation, or the news roundup.
 *
 * Same division of labour as {@see TriggerFlintDigestRoutineJob}: Spark owns
 * the timing (resolved in the user's effective timezone), the routine owns the
 * work and calls back through the Spark MCP tools. Idempotent per
 * (user, local date, routine) via a Redis marker that expires with the local day.
 */
class TriggerFlintRoutineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Routine name => the config key holding its webhook URL. */
    public const ROUTINES = [
        'topics' => 'services.flint_routine.topics_url',
        'reading_list' => 'services.flint_routine.reading_list_url',
        'news_roundup' => 'services.flint_routine.news_roundup_url',
    ];

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public User $user,
        public string $routine,
        public string $localDate,
        public string $timezone,
    ) {}

    /**
     * Cache key marking that this routine has already been triggered for this
     * user's local date.
     */
    public static function markerKey(int|string $userId, string $localDate, string $routine): string
    {
        return "flint:routine-triggered:{$routine}:{$userId}:{$localDate}";
    }

    public function handle(): void
    {
        if (! array_key_exists($this->routine, self::ROUTINES)) {
            Log::warning('Unknown Flint routine; skipping trigger', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
            ]);

            return;
        }

        $url = config(self::ROUTINES[$this->routine]);

        if (empty($url)) {
            Log::info('Flint routine webhook URL not configured; skipping trigger', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
            ]);

            return;
        }

        $markerKey = self::markerKey($this->user->id, $this->localDate, $this->routine);

        if (! Cache::add($markerKey, true, $this->markerTtlSeconds())) {
            Log::info('Flint routine trigger skipped (already triggered)', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        $payload = [
            'user_id' => (string) $this->user->id,
            'routine' => $this->routine,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
            'idempotency_key' => $markerKey,
        ];

        $request = Http::withSentryTracing()->asJson()->timeout(20)->connectTimeout(5);

        if ($secret = config('services.flint_routine.secret')) {
            $request = $request->withToken($secret);
        }

        try {
            $response = $request->post($url, $payload);
        } catch (Throwable $exception) {
            Cache::forget($markerKey);

            throw $exception;
        }

        if (! $response->successful()) {
            Log::error('Flint routine webhook returned a non-2xx response', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'status' => $response->status(),
            ]);

            Cache::forget($markerKey);
            $response->throw();
        }

        Log::info('Flint routine webhook triggered', [
            'user_id' => $this->user->id,
            'routine' => $this->routine,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
        ]);
    }

    /**
     * Seconds remaining until the end of the effective-local day, so the marker
     * naturally expires once the slot has passed.
     */
    private function markerTtlSeconds(): int
    {
        $endOfDay = Carbon::parse($this->localDate, $this->timezone)->endOfDay();

        return max(60, now()->diffInSeconds($endOfDay, false));
    }
}
