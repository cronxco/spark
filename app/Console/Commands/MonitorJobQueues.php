<?php

namespace App\Console\Commands;

use App\Models\Integration;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class MonitorJobQueues extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'integrations:monitor
                          {--alert-threshold=50 : Alert when queue size exceeds this threshold}
                          {--stale-threshold=300 : Alert when jobs are older than this (seconds)}
                          {--notify : Send notifications for issues}
                          {--include-horizon : Include Horizon queue metrics in monitoring}';

    /**
     * The console command description.
     */
    protected $description = 'Monitor integration jobs and system health (complements Laravel Horizon)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $alertThreshold = (int) $this->option('alert-threshold');
        $staleThreshold = (int) $this->option('stale-threshold');
        $shouldNotify = $this->option('notify');
        $includeHorizon = $this->option('include-horizon');

        $this->info('🔍 Monitoring integration system health...');
        $this->comment('💡 Note: Use Laravel Horizon dashboard for detailed queue metrics');

        $issues = [];

        // Check integration processing status (most important for integrations)
        $processingStatus = $this->checkIntegrationProcessingStatus();
        if ($processingStatus['issues']) {
            $issues = array_merge($issues, $processingStatus['issues']);
        }

        // Check integration error rates
        $errorRates = $this->checkIntegrationErrorRates();
        if ($errorRates['issues']) {
            $issues = array_merge($issues, $errorRates['issues']);
        }

        // Only check basic queue metrics if requested (Horizon covers this better)
        if ($includeHorizon) {
            $this->comment('📊 Including basic queue metrics (Horizon recommended for detailed monitoring)');

            $pendingJobs = $this->checkPendingJobs($alertThreshold);
            if ($pendingJobs['issues']) {
                $issues = array_merge($issues, $pendingJobs['issues']);
            }

            $staleJobs = $this->checkStaleJobs($staleThreshold);
            if ($staleJobs['issues']) {
                $issues = array_merge($issues, $staleJobs['issues']);
            }

            $failedJobs = $this->checkFailedJobs();
            if ($failedJobs['issues']) {
                $issues = array_merge($issues, $failedJobs['issues']);
            }

            $this->reportResults($pendingJobs, $staleJobs, $failedJobs, $processingStatus, $issues);
        } else {
            $this->reportIntegrationResults($processingStatus, $errorRates, $issues);
        }

        // Send notifications if requested and there are issues
        if ($shouldNotify && ! empty($issues)) {
            $this->sendNotifications($issues);
        }

        return empty($issues) ? 0 : 1;
    }

    private function checkPendingJobs(int $threshold): array
    {
        $this->info('📋 Checking pending jobs...');

        try {
            $queues = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->groupBy('queue')
                ->get();

            $issues = [];
            $totalPending = 0;

            foreach ($queues as $queue) {
                $totalPending += $queue->count;

                if ($queue->count > $threshold) {
                    $issues[] = [
                        'type' => 'high_queue_size',
                        'queue' => $queue->queue,
                        'count' => $queue->count,
                        'threshold' => $threshold,
                        'message' => "Queue '{$queue->queue}' has {$queue->count} pending jobs (threshold: {$threshold})",
                    ];
                }

                $this->line("  {$queue->queue}: {$queue->count} jobs");
            }

            return [
                'queues' => $queues,
                'total' => $totalPending,
                'issues' => $issues,
            ];

        } catch (Exception $e) {
            $this->error('Failed to check pending jobs: ' . $e->getMessage());

            return ['queues' => [], 'total' => 0, 'issues' => []];
        }
    }

    private function checkStaleJobs(int $threshold): array
    {
        $this->info('⏰ Checking for stale jobs...');

        try {
            $staleJobs = DB::table('jobs')
                ->where('created_at', '<', now()->subSeconds($threshold))
                ->orderBy('created_at')
                ->limit(10)
                ->get();

            $issues = [];

            if ($staleJobs->count() > 0) {
                $issues[] = [
                    'type' => 'stale_jobs',
                    'count' => $staleJobs->count(),
                    'threshold' => $threshold,
                    'oldest' => $staleJobs->first()->created_at,
                    'message' => "{$staleJobs->count()} jobs older than {$threshold} seconds (oldest: {$staleJobs->first()->created_at})",
                ];

                $this->warn("  ⚠️  {$staleJobs->count()} stale jobs found");
                foreach ($staleJobs->take(3) as $job) {
                    $this->line("    - {$job->id}: {$job->created_at} ({$job->queue})");
                }
            } else {
                $this->line('  ✅ No stale jobs found');
            }

            return [
                'stale_jobs' => $staleJobs,
                'issues' => $issues,
            ];

        } catch (Exception $e) {
            $this->error('Failed to check stale jobs: ' . $e->getMessage());

            return ['stale_jobs' => collect(), 'issues' => []];
        }
    }

    private function checkFailedJobs(): array
    {
        $this->info('❌ Checking failed jobs...');

        try {
            $failedCount = DB::table('failed_jobs')->count();

            $issues = [];

            if ($failedCount > 0) {
                $recentFailures = DB::table('failed_jobs')
                    ->orderBy('failed_at', 'desc')
                    ->limit(5)
                    ->get();

                $issues[] = [
                    'type' => 'failed_jobs',
                    'count' => $failedCount,
                    'message' => "{$failedCount} failed jobs in queue",
                ];

                $this->warn("  ⚠️  {$failedCount} failed jobs");
                foreach ($recentFailures as $failure) {
                    $this->line("    - {$failure->id}: {$failure->failed_at}");
                    $this->line("      {$failure->exception}");
                }
            } else {
                $this->line('  ✅ No failed jobs');
            }

            return [
                'failed_count' => $failedCount,
                'issues' => $issues,
            ];

        } catch (Exception $e) {
            $this->error('Failed to check failed jobs: ' . $e->getMessage());

            return ['failed_count' => 0, 'issues' => []];
        }
    }

    private function checkIntegrationProcessingStatus(): array
    {
        $this->info('🔄 Checking integration processing status...');

        try {
            $processingIntegrations = Integration::whereNotNull('processing_started_at')
                ->where('processing_started_at', '<', now()->subMinutes(30))
                ->get();

            $issues = [];

            if ($processingIntegrations->count() > 0) {
                $issues[] = [
                    'type' => 'stuck_integrations',
                    'count' => $processingIntegrations->count(),
                    'message' => "{$processingIntegrations->count()} integrations stuck in processing for >30 minutes",
                ];

                $this->warn("  ⚠️  {$processingIntegrations->count()} integrations stuck in processing");
                foreach ($processingIntegrations->take(3) as $integration) {
                    $this->line("    - {$integration->id} ({$integration->service}): processing since {$integration->processing_started_at}");
                }
            } else {
                $this->line('  ✅ No integrations stuck in processing');
            }

            return [
                'stuck_integrations' => $processingIntegrations,
                'issues' => $issues,
            ];

        } catch (Exception $e) {
            $this->error('Failed to check integration status: ' . $e->getMessage());

            return ['stuck_integrations' => collect(), 'issues' => []];
        }
    }

    private function checkIntegrationErrorRates(): array
    {
        $this->info('⚠️  Checking integration error rates...');

        try {
            $errorIntegrations = Integration::where('error_count', '>', 0)
                ->where('last_error_at', '>', now()->subHours(1))
                ->get();

            $issues = [];

            if ($errorIntegrations->count() > 0) {
                $issues[] = [
                    'type' => 'high_error_integrations',
                    'count' => $errorIntegrations->count(),
                    'message' => "{$errorIntegrations->count()} integrations have errors in the last hour",
                ];

                $this->warn("  ⚠️  {$errorIntegrations->count()} integrations with recent errors");
                foreach ($errorIntegrations->take(3) as $integration) {
                    $this->line("    - {$integration->id} ({$integration->service}): {$integration->error_count} errors, last at {$integration->last_error_at}");
                }
            } else {
                $this->line('  ✅ No integrations with recent errors');
            }

            return [
                'error_integrations' => $errorIntegrations,
                'issues' => $issues,
            ];

        } catch (Exception $e) {
            $this->error('Failed to check integration error rates: ' . $e->getMessage());

            return ['error_integrations' => collect(), 'issues' => []];
        }
    }

    private function reportResults(array $pendingJobs, array $staleJobs, array $failedJobs, array $processingStatus, array $issues): void
    {
        $this->newLine();
        $this->info('📊 System Health Summary:');

        if (empty($issues)) {
            $this->info('✅ All systems healthy!');
        } else {
            $this->warn('⚠️  Found ' . count($issues) . ' issues:');
            foreach ($issues as $issue) {
                $this->line("  • {$issue['message']}");
            }
        }

        $this->line("  📋 Pending jobs: {$pendingJobs['total']}");
        $this->line("  ⏰ Stale jobs: {$staleJobs['stale_jobs']->count()}");
        $this->line("  ❌ Failed jobs: {$failedJobs['failed_count']}");
        $this->line("  🔄 Stuck integrations: {$processingStatus['stuck_integrations']->count()}");
    }

    private function reportIntegrationResults(array $processingStatus, array $errorRates, array $issues): void
    {
        $this->newLine();
        $this->info('🔗 Integration Health Summary:');

        if (empty($issues)) {
            $this->info('✅ All integrations healthy!');
        } else {
            $this->warn('⚠️  Found ' . count($issues) . ' issues:');
            foreach ($issues as $issue) {
                $this->line("  • {$issue['message']}");
            }
        }

        $this->line("  🔄 Stuck integrations: {$processingStatus['stuck_integrations']->count()}");
        $this->line("  ⚠️  Integrations with errors: {$errorRates['error_integrations']->count()}");

        $this->comment('💡 Tip: Use Laravel Horizon dashboard for detailed queue monitoring');
    }

    private function sendNotifications(array $issues): void
    {
        $this->info('📤 Sending notifications...');

        $message = "🚨 Job Queue Alert: {$issues->count()} issues detected\n\n";

        foreach ($issues as $issue) {
            $message .= "• {$issue['message']}\n";
        }

        // Log to application log
        Log::warning('Job queue issues detected', ['issues' => $issues]);

        // Here you could add integrations like:
        // - Slack notifications
        // - Email alerts
        // - PagerDuty/Sentry alerts

        $this->info('✅ Notifications sent (logged to application log)');
    }
}
