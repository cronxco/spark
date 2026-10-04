<?php

namespace App\Services\IntegrationRuns;

use App\Models\Integration;
use Illuminate\Bus\Batch;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Throwable;

/**
 * Groups one integration update into a job batch — the fetch jobs plus the
 * processing jobs they dispatch — and keeps a `last_run` summary in the
 * integration's `configuration`, so "up to date" means the data was
 * processed, not merely fetched.
 *
 * Every write is a single jsonb merge in SQL: it touches only the
 * `last_run` key, so concurrent writers of other configuration keys (the
 * pause toggle, migration progress) are never overwritten, and it never
 * reads the row back into PHP first, so two workers cannot lose each
 * other's update.
 */
class IntegrationRunService
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_FETCHING = 'fetching';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_UP_TO_DATE = 'up_to_date';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /**
     * @var array<int, string>
     */
    public const IN_FLIGHT = [self::STATUS_REQUESTED, self::STATUS_FETCHING, self::STATUS_PROCESSING];

    /**
     * A run still in flight after this long is treated as having stalled
     * (a crashed worker, a deleted job), so it cannot hold an integration in
     * "processing" forever. Comfortably longer than a fetch plus processing
     * with every retry and backoff.
     */
    public const STALL_AFTER_MINUTES = 60;

    /**
     * Queue the fetch jobs as one run.
     *
     * @param  array<int, object>  $fetchJobs
     */
    public function start(Integration $integration, array $fetchJobs): Batch
    {
        // Fetch jobs can queue children while fetching (e.g. scheduled URLs),
        // before BaseFetchJob reaches dispatchProcessingJobsIntoRun().
        foreach ($fetchJobs as $job) {
            $job->through([...$job->middleware, new RunBatchMiddleware]);
        }

        $integrationId = (string) $integration->id;

        return Bus::batch($fetchJobs)
            ->name("integration-run:{$integration->service}:{$integrationId}")
            ->allowFailures(static function (Batch $batch, ?Throwable $exception) use ($integrationId): void {
                app(IntegrationRunService::class)->recordFailure($integrationId, $batch->id, $exception);
            })
            ->before(static function (Batch $batch) use ($integrationId): void {
                app(IntegrationRunService::class)->markRequested($integrationId, $batch->id);
            })
            ->finally(static function (Batch $batch) use ($integrationId): void {
                app(IntegrationRunService::class)->markFinished($integrationId, $batch);
            })
            ->dispatch();
    }

    /**
     * Runs before any job in the batch is queued, so later states always
     * land on top of this one.
     */
    public function markRequested(string $integrationId, string $batchId): void
    {
        $this->write($integrationId, [
            'batch_id' => $batchId,
            'status' => self::STATUS_REQUESTED,
            'requested_at' => now()->toIso8601String(),
            'processed_jobs' => 0,
            'failed_jobs' => 0,
        ], replace: true);
    }

    public function markFetching(string $integrationId, string $batchId): void
    {
        $this->write(
            $integrationId,
            ['status' => self::STATUS_FETCHING],
            defaults: ['started_at' => now()->toIso8601String()],
            batchId: $batchId,
            onlyInFlight: true,
        );
    }

    public function markProcessing(string $integrationId, string $batchId): void
    {
        $this->write(
            $integrationId,
            ['status' => self::STATUS_PROCESSING],
            batchId: $batchId,
            onlyInFlight: true,
        );
    }

    public function recordFailure(string $integrationId, string $batchId, ?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $this->write(
            $integrationId,
            ['error' => Str::limit($exception->getMessage(), 300)],
            batchId: $batchId,
        );
    }

    /**
     * Called once every job in the run has finished or failed for good.
     */
    public function markFinished(string $integrationId, Batch $batch): void
    {
        $status = match (true) {
            $batch->failedJobs === 0 => self::STATUS_UP_TO_DATE,
            $batch->failedJobs >= $batch->totalJobs => self::STATUS_FAILED,
            default => self::STATUS_PARTIAL,
        };

        $this->write($integrationId, [
            'status' => $status,
            'finished_at' => now()->toIso8601String(),
            'total_jobs' => $batch->totalJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs' => $batch->failedJobs,
        ], batchId: $batch->id);
    }

    /**
     * Merge into `configuration.last_run` as `defaults || current || overrides`.
     *
     * @param  array<string, mixed>  $overrides  Always written
     * @param  array<string, mixed>  $defaults  Written only when the run has no value yet
     * @param  string|null  $batchId  Only write while this batch is still the integration's latest run
     * @param  bool  $replace  Start a fresh summary instead of merging into the last one
     */
    private function write(
        string $integrationId,
        array $overrides,
        array $defaults = [],
        ?string $batchId = null,
        bool $onlyInFlight = false,
        bool $replace = false,
    ): void {
        $query = Integration::query()->whereKey($integrationId);

        if ($batchId !== null) {
            $query->where('configuration->last_run->batch_id', $batchId);
        }

        if ($onlyInFlight) {
            $query->whereIn('configuration->last_run->status', self::IN_FLIGHT);
        }

        $connection = $query->getConnection();
        $configuration = "(CASE WHEN jsonb_typeof(configuration) = 'object' THEN configuration ELSE '{}'::jsonb END)";
        $current = $replace
            ? "'{}'::jsonb"
            : "(CASE WHEN jsonb_typeof({$configuration}->'last_run') = 'object' THEN {$configuration}->'last_run' ELSE '{}'::jsonb END)";
        $defaultsJson = $connection->escape(json_encode((object) $defaults, JSON_THROW_ON_ERROR));
        $overridesJson = $connection->escape(json_encode((object) $overrides, JSON_THROW_ON_ERROR));

        $query->update([
            'configuration' => new Expression(
                "jsonb_set({$configuration}, '{last_run}', {$defaultsJson}::jsonb || {$current} || {$overridesJson}::jsonb, true)"
            ),
        ]);
    }
}
