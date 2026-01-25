<?php

namespace App\Console\Commands;

use App\Models\Integration;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class JobSystemHealth extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'job:health
                          {--format=console : Output format (console, json)}
                          {--alerts-only : Only show alerts and warnings}';

    /**
     * The console command description.
     */
    protected $description = 'Display comprehensive job system health dashboard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $format = $this->option('format');
        $alertsOnly = $this->option('alerts-only');

        $healthData = $this->gatherHealthData();

        if ($format === 'json') {
            $this->outputJson($healthData);

            return 0;
        }

        $this->displayConsoleDashboard($healthData, $alertsOnly);

        // Return non-zero exit code if there are critical issues
        return $healthData['status'] === 'critical' ? 1 : 0;
    }

    private function gatherHealthData(): array
    {
        return [
            'timestamp' => now()->toISOString(),
            'status' => 'healthy', // Will be updated based on checks
            'queues' => $this->checkQueueHealth(),
            'integrations' => $this->checkIntegrationHealth(),
            'performance' => $this->checkPerformanceMetrics(),
            'alerts' => $this->gatherAlerts(),
        ];
    }

    private function checkQueueHealth(): array
    {
        try {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            $queues = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->groupBy('queue')
                ->get()
                ->pluck('count', 'queue')
                ->toArray();

            // Check for stale jobs (older than 10 minutes)
            $staleJobs = DB::table('jobs')
                ->where('created_at', '<', now()->subMinutes(10))
                ->count();

            return [
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'queues' => $queues,
                'stale_jobs' => $staleJobs,
            ];

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function checkIntegrationHealth(): array
    {
        try {
            $totalIntegrations = Integration::count();
            $activeIntegrations = Integration::whereNotNull('last_triggered_at')
                ->where('last_triggered_at', '>', now()->subHours(24))
                ->count();

            $stuckIntegrations = Integration::whereNotNull('processing_started_at')
                ->where('processing_started_at', '<', now()->subMinutes(30))
                ->count();

            $errorIntegrations = Integration::where('error_count', '>', 0)
                ->where('last_error_at', '>', now()->subHours(1))
                ->count();

            $integrationsByService = Integration::select('service', DB::raw('count(*) as count'))
                ->groupBy('service')
                ->get()
                ->pluck('count', 'service')
                ->toArray();

            return [
                'total' => $totalIntegrations,
                'active_24h' => $activeIntegrations,
                'stuck' => $stuckIntegrations,
                'with_errors' => $errorIntegrations,
                'by_service' => $integrationsByService,
            ];

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function checkPerformanceMetrics(): array
    {
        try {
            // Average processing times for different job types
            $avgProcessingTimes = DB::table('job_batches')
                ->select('name', DB::raw('avg(created_at - updated_at) as avg_time'))
                ->where('finished_at', '>', now()->subHours(24))
                ->groupBy('name')
                ->get()
                ->pluck('avg_time', 'name')
                ->toArray();

            // Job success rates
            $totalJobs24h = DB::table('job_batches')
                ->where('created_at', '>', now()->subHours(24))
                ->count();

            $successfulJobs24h = DB::table('job_batches')
                ->where('created_at', '>', now()->subHours(24))
                ->whereNotNull('finished_at')
                ->count();

            $successRate = $totalJobs24h > 0 ? ($successfulJobs24h / $totalJobs24h) * 100 : 0;

            return [
                'avg_processing_times' => $avgProcessingTimes,
                'success_rate_24h' => round($successRate, 2),
                'jobs_processed_24h' => $totalJobs24h,
                'cache_hit_rate' => $this->getCacheHitRate(),
            ];

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getCacheHitRate(): float
    {
        // This would need actual cache metrics - placeholder for now
        try {
            $cacheStats = Cache::store()->getStore()->getStats();
            if ($cacheStats && isset($cacheStats['hits'], $cacheStats['misses'])) {
                $total = $cacheStats['hits'] + $cacheStats['misses'];

                return $total > 0 ? ($cacheStats['hits'] / $total) * 100 : 0;
            }
        } catch (Exception $e) {
            // Cache stats not available
        }

        return 0.0;
    }

    private function gatherAlerts(): array
    {
        $alerts = [];

        $health = $this->gatherHealthData();

        // Critical alerts
        if (($health['queues']['failed_jobs'] ?? 0) > 10) {
            $alerts[] = [
                'level' => 'critical',
                'message' => "High failed job count: {$health['queues']['failed_jobs']}",
            ];
        }

        if (($health['integrations']['stuck'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'critical',
                'message' => "Stuck integrations: {$health['integrations']['stuck']}",
            ];
        }

        // Warning alerts
        if (($health['queues']['pending_jobs'] ?? 0) > 100) {
            $alerts[] = [
                'level' => 'warning',
                'message' => "High pending job count: {$health['queues']['pending_jobs']}",
            ];
        }

        if (($health['performance']['success_rate_24h'] ?? 100) < 90) {
            $alerts[] = [
                'level' => 'warning',
                'message' => "Low job success rate: {$health['performance']['success_rate_24h']}%",
            ];
        }

        return $alerts;
    }

    private function displayConsoleDashboard(array $healthData, bool $alertsOnly): void
    {
        if (! $alertsOnly) {
            $this->displayHeader();
            $this->displayQueueStatus($healthData['queues']);
            $this->displayIntegrationStatus($healthData['integrations']);
            $this->displayPerformanceMetrics($healthData['performance']);
        }

        $this->displayAlerts($healthData['alerts']);
        $this->displaySummary($healthData);
    }

    private function displayHeader(): void
    {
        $this->info('🚀 Job System Health Dashboard');
        $this->line('══════════════════════════════════════');
        $this->line('📅 Generated: ' . now()->format('Y-m-d H:i:s T'));
        $this->newLine();
    }

    private function displayQueueStatus(array $queues): void
    {
        $this->info('📋 Queue Status:');

        if (isset($queues['error'])) {
            $this->error("  ❌ Database error: {$queues['error']}");

            return;
        }

        $this->line("  📊 Pending jobs: <comment>{$queues['pending_jobs']}</comment>");
        $this->line("  ❌ Failed jobs: <comment>{$queues['failed_jobs']}</comment>");
        $this->line("  ⏰ Stale jobs: <comment>{$queues['stale_jobs']}</comment>");

        if (! empty($queues['queues'])) {
            $this->line('  📈 By queue:');
            foreach ($queues['queues'] as $queue => $count) {
                $status = $count > 50 ? '<error>⚠️</error>' : '<info>✅</info>';
                $this->line("    {$status} {$queue}: {$count}");
            }
        }

        $this->newLine();
    }

    private function displayIntegrationStatus(array $integrations): void
    {
        $this->info('🔗 Integration Status:');

        if (isset($integrations['error'])) {
            $this->error("  ❌ Database error: {$integrations['error']}");

            return;
        }

        $this->line("  📊 Total integrations: <comment>{$integrations['total']}</comment>");
        $this->line("  ✅ Active (24h): <comment>{$integrations['active_24h']}</comment>");
        $this->line("  🔄 Stuck processing: <comment>{$integrations['stuck']}</comment>");
        $this->line("  ⚠️  With errors: <comment>{$integrations['with_errors']}</comment>");

        if (! empty($integrations['by_service'])) {
            $this->line('  📈 By service:');
            foreach ($integrations['by_service'] as $service => $count) {
                $this->line("    {$service}: {$count}");
            }
        }

        $this->newLine();
    }

    private function displayPerformanceMetrics(array $performance): void
    {
        $this->info('⚡ Performance Metrics:');

        if (isset($performance['error'])) {
            $this->error("  ❌ Database error: {$performance['error']}");

            return;
        }

        $successRate = $performance['success_rate_24h'];
        $successColor = $successRate >= 95 ? 'info' : ($successRate >= 90 ? 'comment' : 'error');

        $this->line("  📈 Success rate (24h): <{$successColor}>{$successRate}%</{$successColor}>");
        $this->line("  🔄 Jobs processed (24h): <comment>{$performance['jobs_processed_24h']}</comment>");
        $this->line('  💾 Cache hit rate: <comment>' . round($performance['cache_hit_rate'], 1) . '%</comment>');

        $this->newLine();
    }

    private function displayAlerts(array $alerts): void
    {
        if (empty($alerts)) {
            $this->info('✅ Alerts: No issues detected');

            return;
        }

        $this->warn('🚨 Alerts:');

        foreach ($alerts as $alert) {
            $icon = $alert['level'] === 'critical' ? '🔴' : '🟡';
            $this->line("  {$icon} {$alert['message']}");
        }

        $this->newLine();
    }

    private function displaySummary(array $healthData): void
    {
        $status = $healthData['status'];
        $alerts = $healthData['alerts'];

        $statusIcon = empty($alerts) ? '✅' : '⚠️';
        $statusColor = empty($alerts) ? 'info' : 'comment';

        $this->line('══════════════════════════════════════');
        $this->line("{$statusIcon} Overall Status: <{$statusColor}>{$status}</{$statusColor}>");
        $this->line('📊 Total alerts: ' . count($alerts));

        if (! empty($alerts)) {
            $criticalCount = count(array_filter($alerts, fn ($a) => $a['level'] === 'critical'));
            $warningCount = count($alerts) - $criticalCount;

            if ($criticalCount > 0) {
                $this->line("🔴 Critical: {$criticalCount}");
            }
            if ($warningCount > 0) {
                $this->line("🟡 Warnings: {$warningCount}");
            }
        }
    }

    private function outputJson(array $healthData): void
    {
        // Add status based on alerts
        $healthData['status'] = empty($healthData['alerts']) ? 'healthy' :
            (count(array_filter($healthData['alerts'], fn ($a) => $a['level'] === 'critical')) > 0 ? 'critical' : 'warning');

        $this->line(json_encode($healthData, JSON_PRETTY_PRINT));
    }
}
