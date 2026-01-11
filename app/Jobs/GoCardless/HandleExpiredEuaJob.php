<?php

namespace App\Jobs\GoCardless;

use App\Models\IntegrationGroup;
use App\Notifications\IntegrationAuthenticationFailed;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class HandleExpiredEuaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1; // Don't retry this job

    public function __construct(
        protected string $groupId,
        protected ?string $euaId = null,
        protected array $errorResponse = []
    ) {}

    public function handle(): void
    {
        $group = IntegrationGroup::find($this->groupId);

        if (! $group) {
            Log::warning('HandleExpiredEuaJob: Integration group not found', [
                'group_id' => $this->groupId,
            ]);

            return;
        }

        // Check if already marked as expired (prevent duplicate processing)
        if ($group->auth_metadata['eua_expired'] ?? false) {
            Log::info('HandleExpiredEuaJob: EUA already marked as expired, skipping', [
                'group_id' => $this->groupId,
            ]);

            return;
        }

        Log::info('HandleExpiredEuaJob: Processing expired EUA', [
            'group_id' => $this->groupId,
            'eua_id' => $this->euaId,
        ]);

        // Step 1: Mark Integration Group as requiring reconfirmation
        $authMetadata = $group->auth_metadata ?? [];
        $authMetadata['eua_expired'] = true;
        $authMetadata['eua_expired_at'] = now()->toISOString();
        $authMetadata['requires_reconfirmation'] = true;
        $group->update(['auth_metadata' => $authMetadata]);

        Log::info('HandleExpiredEuaJob: Marked group as requiring reconfirmation', [
            'group_id' => $this->groupId,
        ]);

        // Step 2: Pause All Instances in Group
        $pausedCount = 0;
        $group->integrations()->each(function ($integration) use (&$pausedCount) {
            $config = $integration->configuration ?? [];
            if (! ($config['paused'] ?? false)) {
                $config['paused'] = true;
                $integration->update(['configuration' => $config]);
                $pausedCount++;
            }
        });

        Log::info('HandleExpiredEuaJob: Paused integrations', [
            'group_id' => $this->groupId,
            'paused_count' => $pausedCount,
        ]);

        // Step 3: Delete Pending Jobs from the pull queue
        $this->deletePendingJobs($group);

        // Step 4: Send Single Notification
        $this->sendNotification($group);

        Log::info('HandleExpiredEuaJob: Completed EUA expiry handling', [
            'group_id' => $this->groupId,
        ]);
    }

    public function failed(Exception $exception): void
    {
        Log::error('HandleExpiredEuaJob: Job failed permanently', [
            'group_id' => $this->groupId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    protected function deletePendingJobs(IntegrationGroup $group): void
    {
        try {
            $integrationIds = $group->integrations()->pluck('id')->toArray();

            // Delete jobs from the 'pull' queue (where integration fetch jobs are dispatched)
            $redis = Redis::connection();
            $queueKey = 'queues:pull';

            // Get all jobs from the pull queue
            $jobs = $redis->lrange($queueKey, 0, -1);

            $deletedCount = 0;
            foreach ($jobs as $job) {
                $decoded = json_decode($job, true);

                // Safely check if this job belongs to one of our integrations
                try {
                    if (isset($decoded['data']['command'])) {
                        $payload = unserialize($decoded['data']['command']);

                        // Check if this job has an integration property matching our group
                        if (isset($payload->integration) &&
                            in_array($payload->integration->id, $integrationIds)) {
                            // Remove this specific job from the queue
                            $redis->lrem($queueKey, 1, $job);
                            $deletedCount++;
                        }
                    }
                } catch (Exception $e) {
                    // Skip jobs that can't be unserialized
                    Log::debug('HandleExpiredEuaJob: Could not unserialize job during cleanup', [
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            Log::info('HandleExpiredEuaJob: Deleted pending jobs for expired EUA', [
                'group_id' => $group->id,
                'deleted_count' => $deletedCount,
            ]);
        } catch (Exception $e) {
            Log::error('HandleExpiredEuaJob: Failed to delete pending jobs', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendNotification(IntegrationGroup $group): void
    {
        try {
            $integration = $group->integrations()->first();
            if (! $integration) {
                Log::warning('HandleExpiredEuaJob: No integrations found for group, skipping notification', [
                    'group_id' => $group->id,
                ]);

                return;
            }

            // Get bank name for user-friendly message
            $bankName = $group->auth_metadata['gocardless_institution_name'] ?? 'bank';

            $group->user->notify(
                new IntegrationAuthenticationFailed(
                    $integration,
                    "Your {$bankName} connection has expired. Please reconnect to continue syncing your transactions.",
                    [
                        'eua_expired' => true,
                        'requires_reconfirmation' => true,
                        'bank_name' => $bankName,
                    ]
                )
            );

            Log::info('HandleExpiredEuaJob: Sent notification to user', [
                'group_id' => $group->id,
                'user_id' => $group->user_id,
                'bank_name' => $bankName,
            ]);
        } catch (Exception $e) {
            Log::error('HandleExpiredEuaJob: Failed to send notification', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
