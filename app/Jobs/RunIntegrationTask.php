<?php

namespace App\Jobs;

use App\Models\Integration;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunIntegrationTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes

    public $tries = 1;

    public function __construct(public Integration $integration)
    {
        // Ensure we run on desired queue by caller; default set by dispatcher
    }

    public function handle(): void
    {
        $config = $this->integration->configuration ?? [];

        $mode = (string) ($config['task_mode'] ?? 'artisan');
        $queue = (string) ($config['task_queue'] ?? 'pull');

        try {
            if ($mode === 'job') {
                $jobClass = (string) ($config['task_job_class'] ?? '');
                $payload = $config['task_payload'] ?? [];

                if ($jobClass === '' || ! class_exists($jobClass)) {
                    throw new Exception('Invalid task_job_class');
                }

                // Optional whitelist enforcement for job classes
                $allowedJobs = config('app.allowed_task_jobs');
                if (is_array($allowedJobs) && ! in_array($jobClass, $allowedJobs, true)) {
                    throw new Exception('Job class not allowed');
                }

                $jobInstance = null;

                // Try common construction patterns
                if (method_exists($jobClass, 'dispatch')) {
                    // Static dispatch available; delegate
                    $jobClass::dispatch(...(is_array($payload) ? array_values($payload) : []))
                        ->onQueue($queue);
                } else {
                    // Try to instantiate with payload as single argument or no-arg
                    try {
                        $jobInstance = new $jobClass($payload);
                    } catch (Throwable $e) {
                        $jobInstance = new $jobClass;
                    }

                    dispatch($jobInstance)->onQueue($queue);
                }

            } else {
                $command = (string) ($config['task_command'] ?? '');
                $payload = $config['task_payload'] ?? [];

                if ($command === '') {
                    throw new Exception('Invalid task_command');
                }

                // Enforce optional whitelist if configured
                $allowed = config('app.allowed_task_commands');
                if (is_array($allowed) && ! in_array($command, $allowed, true)) {
                    throw new Exception('Command not allowed');
                }

                Artisan::call($command, is_array($payload) ? $payload : []);
            }

            $this->integration->markAsSuccessfullyUpdated();

        } catch (Throwable $e) {
            Log::error('RunIntegrationTask failed', [
                'integration_id' => $this->integration->id,
                'service' => $this->integration->service,
                'error' => $e->getMessage(),
            ]);
            $this->integration->markAsFailed();
            throw $e;
        }
    }
}
