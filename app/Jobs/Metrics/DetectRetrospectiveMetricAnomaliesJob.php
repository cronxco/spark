<?php

namespace App\Jobs\Metrics;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectRetrospectiveMetricAnomaliesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public $tries = 2;

    public $backoff = [60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * This job runs daily and detects anomalies for events from yesterday
     * where the integration instance type has anomaly_detection_mode = 'retrospective'.
     */
    public function handle(): void
    {
        $yesterday = now()->subDay()->toDateString();

        Log::info('Starting retrospective anomaly detection', [
            'date' => $yesterday,
        ]);

        // Get all events from yesterday with value and value_unit
        $events = Event::whereDate('time', $yesterday)
            ->whereNotNull('value')
            ->whereNotNull('value_unit')
            ->with(['integration'])
            ->get();

        $processedCount = 0;
        $skippedCount = 0;

        foreach ($events as $event) {
            // Skip if event has no integration
            if (! $event->integration) {
                $skippedCount++;

                continue;
            }

            // Check if this integration should use retrospective detection
            $shouldProcessRetrospectively = $this->shouldProcessRetrospectively($event);

            if (! $shouldProcessRetrospectively) {
                $skippedCount++;

                continue;
            }

            // Check for user override
            $userOverride = $this->getUserOverride($event);
            if ($userOverride !== null && $userOverride !== 'retrospective') {
                $skippedCount++;

                continue;
            }

            // Dispatch the standard anomaly detection job for this event
            DetectMetricAnomaliesJob::dispatch($event);
            $processedCount++;
        }

        Log::info('Completed retrospective anomaly detection', [
            'date' => $yesterday,
            'total_events' => $events->count(),
            'processed' => $processedCount,
            'skipped' => $skippedCount,
        ]);
    }

    /**
     * Check if the event's integration should use retrospective detection
     */
    protected function shouldProcessRetrospectively(Event $event): bool
    {
        $plugin = PluginRegistry::getPlugin($event->service);
        if (! $plugin) {
            return false;
        }

        $instanceTypes = $plugin::getInstanceTypes();
        $instanceType = $event->integration->instance_type;

        if (! isset($instanceTypes[$instanceType]['anomaly_detection_mode'])) {
            return false;
        }

        $mode = $instanceTypes[$instanceType]['anomaly_detection_mode'];

        return $mode === 'retrospective';
    }

    /**
     * Get user override for this metric if set
     */
    protected function getUserOverride(Event $event): ?string
    {
        $user = $event->integration->user;
        $settings = $user->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $overrides = $metricTracking['anomaly_detection_mode_override'] ?? [];

        $identifier = "{$event->service}.{$event->action}.{$event->value_unit}";

        return $overrides[$identifier] ?? null;
    }
}
