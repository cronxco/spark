<?php

namespace App\Jobs\Flint;

use App\Models\EventObject;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class StaleCoachingSessionCleanupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[Flint] [CLEANUP] Starting stale coaching session cleanup');

        // Find active coaching sessions older than 7 days
        $staleSessions = EventObject::where('concept', 'flint')
            ->where('type', 'coaching_session')
            ->whereRaw("metadata->>'status' = 'active'")
            ->where('time', '<=', now()->subDays(7))
            ->whereNull('deleted_at')
            ->get();

        $expiredCount = 0;
        $failedCount = 0;

        foreach ($staleSessions as $session) {
            try {
                $metadata = $session->metadata;
                $metadata['status'] = 'expired';
                $metadata['expired_at'] = now()->toIso8601String();
                $metadata['expired_reason'] = 'No response after 7 days';

                $session->metadata = $metadata;
                $session->save();

                $expiredCount++;

                Log::debug('[Flint] [CLEANUP] Expired stale coaching session', [
                    'session_id' => $session->id,
                    'user_id' => $session->user_id,
                    'age_days' => now()->diffInDays($session->time),
                ]);
            } catch (Exception $e) {
                $failedCount++;

                Log::warning('[Flint] [CLEANUP] Failed to expire coaching session', [
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[Flint] [CLEANUP] Completed stale coaching session cleanup', [
            'total_stale' => $staleSessions->count(),
            'expired' => $expiredCount,
            'failed' => $failedCount,
        ]);
    }
}
