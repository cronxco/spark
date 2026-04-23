<?php

namespace App\Providers;

use App\Integrations\Contracts\SupportsTaskPipeline;
use App\Jobs\TaskPipeline\Tasks\CalculateMetricStatsTask;
use App\Jobs\TaskPipeline\Tasks\DetectAnomaliesTask;
use App\Jobs\TaskPipeline\Tasks\DetectTrendsTask;
use App\Jobs\TaskPipeline\Tasks\DownloadImagesToMediaLibraryTask;
use App\Jobs\TaskPipeline\Tasks\FindReceiptForTransactionTask;
use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Jobs\TaskPipeline\Tasks\LinkTransactionsTask;
use App\Jobs\TaskPipeline\Tasks\MatchReceiptToTransactionTask;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

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
        // Download Images to Media Library - downloads external images and stores them with deduplication
        TaskRegistry::register(new TaskDefinition(
            key: 'download_images_to_media_library',
            name: 'Download Images to Media Library',
            description: 'Download external images and store in Media Library with responsive variants',
            jobClass: DownloadImagesToMediaLibraryTask::class,
            appliesTo: ['event', 'block', 'object'],
            conditions: [],
            dependencies: [],
            queue: 'tasks',
            priority: 100, // Run before embedding generation
            runOnCreate: true,
            runOnUpdate: false,
            shouldRun: function ($model) {
                // For Blocks: check for media_url or image in metadata
                if ($model instanceof Block) {
                    if ($model->media_url && ! $model->hasMedia('downloaded_images')) {
                        return true;
                    }
                    $metadata = $model->metadata ?? [];
                    $imageUrl = $metadata['image'] ?? $metadata['image_url'] ?? null;

                    return $imageUrl && ! $model->hasMedia('downloaded_images');
                }

                // For EventObjects: check for media_url
                if ($model instanceof EventObject) {
                    return $model->media_url && ! $model->hasMedia('downloaded_images');
                }

                // For Events: check if any blocks or objects have images to download
                if ($model instanceof Event) {
                    // Check blocks
                    foreach ($model->blocks as $block) {
                        if ($block->media_url && ! $block->hasMedia('downloaded_images')) {
                            return true;
                        }
                        $metadata = $block->metadata ?? [];
                        $imageUrl = $metadata['image'] ?? $metadata['image_url'] ?? null;
                        if ($imageUrl && ! $block->hasMedia('downloaded_images')) {
                            return true;
                        }
                    }

                    // Check target object
                    if ($model->target && $model->target->media_url && ! $model->target->hasMedia('downloaded_images')) {
                        return true;
                    }

                    // Check actor object
                    if ($model->actor && $model->actor->media_url && ! $model->actor->hasMedia('downloaded_images')) {
                        return true;
                    }
                }

                return false;
            },
        ));

        // Embedding Generation - runs on all model types when OpenAI is configured
        TaskRegistry::register(new TaskDefinition(
            key: 'generate_embedding',
            name: 'Generate Embedding',
            description: 'Generate AI embedding for semantic search',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event', 'block', 'object'],
            conditions: [],
            dependencies: [],
            queue: 'tasks',
            priority: 95,
            runOnCreate: true,
            runOnUpdate: true,
            shouldRun: fn () => config('services.openai.api_key') !== null,
        ));

        // Metric Statistics - calculates baseline stats for metrics
        TaskRegistry::register(new TaskDefinition(
            key: 'calculate_metric_stats',
            name: 'Calculate Metric Statistics',
            description: 'Calculate mean, stddev, and normal bounds for metrics',
            jobClass: CalculateMetricStatsTask::class,
            appliesTo: ['event'],
            conditions: [],
            dependencies: [],
            queue: 'tasks',
            priority: 90,
            runOnCreate: true,
            runOnUpdate: true,
            shouldRun: function ($model) {
                return $model->value !== null && $model->value_unit !== null;
            },
        ));

        // Anomaly Detection - detects anomalous metric values
        TaskRegistry::register(new TaskDefinition(
            key: 'detect_anomalies',
            name: 'Detect Anomalies',
            description: 'Detect if metric value is anomalous based on baseline statistics',
            jobClass: DetectAnomaliesTask::class,
            appliesTo: ['event'],
            conditions: [],
            dependencies: ['calculate_metric_stats'], // Requires stats first
            queue: 'tasks',
            priority: 80,
            runOnCreate: true,
            runOnUpdate: false,
            shouldRun: function ($model) {
                if (! $model->value || ! $model->value_unit) {
                    return false;
                }

                // Only run if integration has real-time anomaly detection enabled
                $integration = $model->integration;
                if (! $integration) {
                    return false;
                }

                // Check integration's anomaly_detection_mode
                $mode = $integration->getAnomalyDetectionMode();

                // Only run for realtime mode (default if not configured), skip for retrospective and disabled
                return $mode === 'realtime' || $mode === null;
            },
        ));

        // Trend Detection - detects metric trends over time
        TaskRegistry::register(new TaskDefinition(
            key: 'detect_trends',
            name: 'Detect Trends',
            description: 'Detect weekly, monthly, and quarterly metric trends',
            jobClass: DetectTrendsTask::class,
            appliesTo: ['event'],
            conditions: [],
            dependencies: ['calculate_metric_stats'],
            queue: 'tasks',
            priority: 70,
            runOnCreate: false, // Only scheduled
            runOnUpdate: false,
            shouldRun: function ($model) {
                return $model->value !== null && $model->value_unit !== null;
            },
        ));

        // Receipt Matching (Forward) - matches receipts to transactions
        TaskRegistry::register(new TaskDefinition(
            key: 'match_receipt_to_transaction',
            name: 'Match Receipt to Transaction',
            description: 'Find matching transaction for a receipt',
            jobClass: MatchReceiptToTransactionTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => 'receipt',
                'action' => 'had_receipt_from',
            ],
            dependencies: config('services.openai.api_key') ? ['generate_embedding'] : [],
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
            jobClass: FindReceiptForTransactionTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => ['monzo', 'gocardless'],
                'domain' => 'money',
            ],
            dependencies: config('services.openai.api_key') ? ['generate_embedding'] : [],
            queue: 'tasks',
            priority: 60,
            runOnCreate: true,
            runOnUpdate: false,
            shouldRun: function ($model) {
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
            jobClass: LinkTransactionsTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => ['monzo', 'gocardless'],
                'domain' => 'money',
            ],
            dependencies: config('services.openai.api_key') ? ['generate_embedding'] : [],
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
        // Scan app/Integrations directory for plugin classes
        $integrationPath = app_path('Integrations');

        if (! is_dir($integrationPath)) {
            return;
        }

        // Get all PHP files in Integrations directory (recursively)
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($integrationPath)
        );

        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            // Convert file path to class name
            $relativePath = str_replace($integrationPath . '/', '', $file->getPathname());
            $className = 'App\\Integrations\\' . str_replace(
                ['/', '.php'],
                ['\\', ''],
                $relativePath
            );

            // Check if class exists and implements SupportsTaskPipeline
            if (! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->implementsInterface(SupportsTaskPipeline::class)) {
                // Get task definitions from the plugin
                $tasks = $className::getTaskDefinitions();

                foreach ($tasks as $task) {
                    // Mark as registered by this plugin
                    $task->registeredBy = $className;
                    TaskRegistry::register($task);
                }
            }
        }
    }
}
