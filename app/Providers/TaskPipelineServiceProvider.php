<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\TaskPipeline\TaskRegistry;
use App\Services\TaskPipeline\TaskDefinition;

class TaskPipelineServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        $this->registerCoreTasks();
        $this->registerPluginTasks();
    }

    /**
     * Register core task definitions
     */
    protected function registerCoreTasks(): void
    {
        // Embedding Generation - runs on all model types when OpenAI is configured
        TaskRegistry::register(new TaskDefinition(
            key: 'generate_embedding',
            name: 'Generate Embedding',
            description: 'Generate AI embedding for semantic search',
            jobClass: \App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask::class,
            appliesTo: ['event', 'block', 'object'],
            conditions: [],
            dependencies: [],
            queue: 'tasks',
            priority: 100,
            runOnCreate: true,
            runOnUpdate: true,
            shouldRun: fn() => config('services.openai.api_key') !== null,
        ));

        // Metric Statistics - calculates baseline stats for metrics
        TaskRegistry::register(new TaskDefinition(
            key: 'calculate_metric_stats',
            name: 'Calculate Metric Statistics',
            description: 'Calculate mean, stddev, and normal bounds for metrics',
            jobClass: \App\Jobs\TaskPipeline\Tasks\CalculateMetricStatsTask::class,
            appliesTo: ['event'],
            conditions: [],
            dependencies: [],
            queue: 'tasks',
            priority: 90,
            runOnCreate: false, // Only scheduled
            runOnUpdate: false,
            shouldRun: function($model) {
                return $model->value !== null && $model->value_unit !== null;
            },
        ));

        // Anomaly Detection - detects anomalous metric values
        TaskRegistry::register(new TaskDefinition(
            key: 'detect_anomalies',
            name: 'Detect Anomalies',
            description: 'Detect if metric value is anomalous based on baseline statistics',
            jobClass: \App\Jobs\TaskPipeline\Tasks\DetectAnomaliesTask::class,
            appliesTo: ['event'],
            conditions: [],
            dependencies: ['calculate_metric_stats'], // Requires stats first
            queue: 'tasks',
            priority: 80,
            runOnCreate: true,
            runOnUpdate: false,
            shouldRun: function($model) {
                if (!$model->value || !$model->value_unit) {
                    return false;
                }

                // Only run if integration has real-time anomaly detection enabled
                $integration = $model->integration;
                if (!$integration) {
                    return false;
                }

                // TODO: Check integration's anomaly_detection_mode when that field exists
                // For now, assume it's enabled
                return true;
            },
        ));

        // Trend Detection - detects metric trends over time
        TaskRegistry::register(new TaskDefinition(
            key: 'detect_trends',
            name: 'Detect Trends',
            description: 'Detect weekly, monthly, and quarterly metric trends',
            jobClass: \App\Jobs\TaskPipeline\Tasks\DetectTrendsTask::class,
            appliesTo: ['event'],
            conditions: [],
            dependencies: ['calculate_metric_stats'],
            queue: 'tasks',
            priority: 70,
            runOnCreate: false, // Only scheduled
            runOnUpdate: false,
            shouldRun: function($model) {
                return $model->value !== null && $model->value_unit !== null;
            },
        ));

        // Receipt Matching (Forward) - matches receipts to transactions
        TaskRegistry::register(new TaskDefinition(
            key: 'match_receipt_to_transaction',
            name: 'Match Receipt to Transaction',
            description: 'Find matching transaction for a receipt',
            jobClass: \App\Jobs\TaskPipeline\Tasks\MatchReceiptToTransactionTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => 'receipt',
                'action' => 'had_receipt_from',
            ],
            dependencies: ['generate_embedding'],
            queue: 'tasks',
            priority: 60,
            runOnCreate: true,
            runOnUpdate: false,
        ));

        // Receipt Matching (Reverse) - finds receipts for transactions
        TaskRegistry::register(new TaskDefinition(
            key: 'find_receipt_for_transaction',
            name: 'Find Receipt for Transaction',
            description: 'Find matching receipt for a financial transaction',
            jobClass: \App\Jobs\TaskPipeline\Tasks\FindReceiptForTransactionTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => ['monzo', 'gocardless'],
                'domain' => 'money',
            ],
            dependencies: ['generate_embedding'],
            queue: 'tasks',
            priority: 60,
            runOnCreate: true,
            runOnUpdate: false,
            shouldRun: function($model) {
                // Only run for payment-related actions
                $paymentActions = [
                    'had_card_payment',
                    'had_transaction',
                    'had_account_debit',
                    'had_faster_payment',
                ];

                return in_array($model->action, $paymentActions);
            },
        ));

        // Transaction Linking - links related transactions across providers
        TaskRegistry::register(new TaskDefinition(
            key: 'link_transactions',
            name: 'Link Related Transactions',
            description: 'Find and link related transactions across providers',
            jobClass: \App\Jobs\TaskPipeline\Tasks\LinkTransactionsTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => ['monzo', 'gocardless'],
                'domain' => 'money',
            ],
            dependencies: ['generate_embedding'],
            queue: 'tasks',
            priority: 50,
            runOnCreate: true,
            runOnUpdate: false,
        ));
    }

    /**
     * Register tasks from plugins that support the task pipeline
     */
    protected function registerPluginTasks(): void
    {
        // Plugin task registration will be implemented when we have a plugin registry
        // Plugins implementing SupportsTaskPipeline will be auto-discovered
    }
}
