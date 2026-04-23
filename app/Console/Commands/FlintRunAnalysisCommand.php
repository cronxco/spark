<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AgentOrchestrationService;
use Exception;
use Illuminate\Console\Command;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;

class FlintRunAnalysisCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flint:run-analysis
                            {user? : The user ID or email to run analysis for}
                            {--mode=digest : Analysis mode: continuous, pre-digest, digest, or pattern}
                            {--period=morning : Period for digest: morning, afternoon, or evening}
                            {--all : Run for all users with Flint enabled}';

    /**
     * The console command description.
     */
    protected $description = 'Run Flint multi-agent analysis for a user (for testing and manual execution)';

    /**
     * Execute the console command.
     */
    public function handle(AgentOrchestrationService $orchestration): int
    {
        $transactionContext = new TransactionContext;
        $transactionContext->setName('flint.manual_analysis');
        $transactionContext->setOp('command');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        try {
            $mode = $this->option('mode');
            $period = $this->option('period');
            $all = $this->option('all');

            // Validate mode
            if (! in_array($mode, ['continuous', 'pre-digest', 'digest', 'pattern'])) {
                $this->error("Invalid mode: {$mode}. Must be one of: continuous, pre-digest, digest, pattern");

                return self::FAILURE;
            }

            // Get users
            if ($all) {
                $users = User::query()
                    ->whereHas('integrations', function ($query) {
                        $query->where('service', 'flint');
                    })
                    ->get();

                $this->info("Running {$mode} analysis for {$users->count()} users...");
            } else {
                $userIdentifier = $this->argument('user');

                if (! $userIdentifier) {
                    $this->error('Please specify a user ID/email or use --all flag');

                    return self::FAILURE;
                }

                // Try to find user by ID or email
                $user = is_numeric($userIdentifier)
                    ? User::find($userIdentifier)
                    : User::where('email', $userIdentifier)->first();

                if (! $user) {
                    $this->error("User not found: {$userIdentifier}");

                    return self::FAILURE;
                }

                $users = collect([$user]);
                $this->info("Running {$mode} analysis for user: {$user->email} (ID: {$user->id})");
            }

            // Run analysis for each user
            $successCount = 0;
            $errorCount = 0;

            foreach ($users as $user) {
                try {
                    \Sentry\configureScope(function (Scope $scope) use ($user) {
                        $scope->setUser([
                            'id' => $user->id,
                            'email' => $user->email,
                        ]);
                    });

                    $this->newLine();
                    $this->info("Processing user: {$user->email}");

                    $result = match ($mode) {
                        'continuous' => $this->runContinuousAnalysis($orchestration, $user),
                        'pre-digest' => $this->runPreDigestRefresh($orchestration, $user),
                        'digest' => $this->runDigestGeneration($orchestration, $user, $period),
                        'pattern' => $this->runPatternDetection($orchestration, $user),
                    };

                    if ($result !== false) {
                        $this->info('✓ Analysis completed successfully');
                        $successCount++;
                    } else {
                        $this->error('✗ Analysis failed');
                        $errorCount++;
                    }
                } catch (Exception $e) {
                    $this->error("✗ Error: {$e->getMessage()}");
                    $errorCount++;
                    \Sentry\captureException($e);
                }
            }

            $this->newLine();
            $this->info("Summary: {$successCount} successful, {$errorCount} errors");

            $transaction->setData([
                'mode' => $mode,
                'users_count' => $users->count(),
                'success_count' => $successCount,
                'error_count' => $errorCount,
            ]);

            $transaction->finish();

            return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Exception $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            $this->error("Fatal error: {$e->getMessage()}");
            \Sentry\captureException($e);

            return self::FAILURE;
        }
    }

    private function runContinuousAnalysis(AgentOrchestrationService $orchestration, User $user): bool
    {
        $this->line('Running continuous background analysis...');

        $results = $orchestration->runContinuousBackgroundAnalysis($user);

        $this->line('Domain results:');
        foreach ($results as $domain => $result) {
            if ($result === null) {
                $this->line("  - {$domain}: skipped (no events)");
            } else {
                $insightCount = count($result['insights'] ?? []);
                $this->line("  - {$domain}: {$insightCount} insights");
            }
        }

        return true;
    }

    private function runPreDigestRefresh(AgentOrchestrationService $orchestration, User $user): bool
    {
        $this->line('Running pre-digest refresh...');

        $results = $orchestration->runPreDigestRefresh($user);

        $totalBlocks = 0;
        foreach ($results as $domain => $result) {
            if ($result !== null && isset($result['blocks_created'])) {
                $totalBlocks += $result['blocks_created'];
            }
        }

        $this->line("Created {$totalBlocks} blocks");

        return true;
    }

    private function runDigestGeneration(AgentOrchestrationService $orchestration, User $user, string $period): bool
    {
        $this->line("Running digest generation ({$period})...");

        $digestBlockId = $orchestration->runDigestGeneration($user, $period);

        if ($digestBlockId) {
            $this->line("Digest block created: {$digestBlockId}");
            $this->line('Notification sent to user');

            return true;
        }

        return false;
    }

    private function runPatternDetection(AgentOrchestrationService $orchestration, User $user): bool
    {
        $this->line('Running pattern detection (90-day analysis)...');

        $patterns = $orchestration->runPatternDetection($user);

        $this->line('Detected patterns:');
        foreach ($patterns as $pattern) {
            $this->line("  - [{$pattern['pattern_type']}] {$pattern['title']} (confidence: {$pattern['confidence']})");
        }

        if (empty($patterns)) {
            $this->line('  No patterns detected (confidence threshold: 0.6)');
        }

        return true;
    }
}
