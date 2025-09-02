<?php

namespace App\Console\Commands;

use App\Models\Integration;
use Illuminate\Console\Command;

class CheckIntegrations extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'integrations:check
                          {--stuck : Show only stuck integrations}
                          {--errors : Show only integrations with errors}';

    /**
     * The console command description.
     */
    protected $description = 'Check integration health and status (complements Laravel Horizon)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $showStuck = $this->option('stuck');
        $showErrors = $this->option('errors');

        if ($showStuck) {
            return $this->checkStuckIntegrations();
        }

        if ($showErrors) {
            return $this->checkErrorIntegrations();
        }

        return $this->showFullHealthCheck();
    }

    private function showFullHealthCheck(): int
    {
        $this->info('🔍 Integration Health Check');
        $this->comment('💡 Use Laravel Horizon for detailed queue monitoring');

        $this->newLine();

        // Check stuck integrations
        $stuck = $this->checkStuckIntegrations(false);
        $this->newLine();

        // Check error integrations
        $errors = $this->checkErrorIntegrations(false);
        $this->newLine();

        // Summary
        $totalIssues = $stuck + $errors;

        if ($totalIssues === 0) {
            $this->info('✅ All integrations healthy!');

            return 0;
        } else {
            $this->warn("⚠️  Found {$totalIssues} issues to address");

            return 1;
        }
    }

    private function checkStuckIntegrations(bool $returnCode = true): int
    {
        $stuckIntegrations = Integration::whereNotNull('processing_started_at')
            ->where('processing_started_at', '<', now()->subMinutes(30))
            ->get();

        if ($stuckIntegrations->isEmpty()) {
            $this->line('✅ No integrations stuck in processing');

            return 0;
        }

        $this->warn("⚠️  {$stuckIntegrations->count()} integrations stuck in processing (>30 minutes):");

        foreach ($stuckIntegrations as $integration) {
            $duration = now()->diffInMinutes($integration->processing_started_at);
            $this->line("  🔄 {$integration->id} ({$integration->service}) - stuck for {$duration} minutes");
        }

        if ($returnCode) {
            $this->comment('💡 These integrations may need manual intervention');
        }

        return $stuckIntegrations->count();
    }

    private function checkErrorIntegrations(bool $returnCode = true): int
    {
        $errorIntegrations = Integration::where('error_count', '>', 0)
            ->where('last_error_at', '>', now()->subHours(1))
            ->get();

        if ($errorIntegrations->isEmpty()) {
            $this->line('✅ No integrations with recent errors');

            return 0;
        }

        $this->warn("⚠️  {$errorIntegrations->count()} integrations with errors in the last hour:");

        foreach ($errorIntegrations as $integration) {
            $this->line("  ❌ {$integration->id} ({$integration->service}) - {$integration->error_count} errors");
            if ($integration->last_error_message) {
                $this->line('    Last error: ' . substr($integration->last_error_message, 0, 100) . '...');
            }
        }

        if ($returnCode) {
            $this->comment('💡 Check integration configurations and API credentials');
        }

        return $errorIntegrations->count();
    }
}
