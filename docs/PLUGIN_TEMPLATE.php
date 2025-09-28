<?php

/**
 * Example/Template Plugin Class
 *
 * Use this file as a reference when creating new integration plugins.
 * Copy this template and modify it to suit your integration needs.
 *
 * IMPORTANT: Always use $this->event->createBlock() to create blocks,
 * never use $this->event->blocks()->create() as it can create duplicates.
 */

declare(strict_types=1);

namespace App\Integrations\Plugins;

use App\Models\Block;
use Illuminate\Support\Collection;
use Throwable;

class ExamplePlugin extends BasePlugin
{
    /**
     * The service/platform this plugin integrates with
     */
    public static function getService(): string
    {
        return 'example_service';
    }

    /**
     * Display name for the plugin in the UI
     */
    public static function getDisplayName(): string
    {
        return 'Example Service';
    }

    /**
     * Description of what this plugin does
     */
    public static function getDescription(): string
    {
        return 'Tracks activities and metrics from Example Service';
    }

    /**
     * Icon to display for this plugin (heroicons)
     */
    public static function getIcon(): string
    {
        return 'o-square-3-stack-3d';
    }

    /**
     * The type of service (api, webhook, etc.)
     */
    public static function getServiceType(): string
    {
        return 'api';
    }

    /**
     * The domain/website for this service
     */
    public static function getDomain(): string
    {
        return 'example.com';
    }

    /**
     * Define the action types this plugin can handle
     */
    public static function getActionTypes(): array
    {
        return [
            'activity_completed' => [
                'display_name' => 'Activity Completed',
                'description' => 'User completed an activity',
                'icon' => 'o-check-circle',
                'value_unit' => null,
                'display_with_object' => true,
            ],
            'metric_recorded' => [
                'display_name' => 'Metric Recorded',
                'description' => 'A metric value was recorded',
                'icon' => 'o-chart-bar',
                'value_unit' => 'points',
                'display_with_object' => false,
            ],
        ];
    }

    /**
     * Define the object types this plugin works with
     */
    public static function getObjectTypes(): array
    {
        return [
            'workout' => [
                'display_name' => 'Workout',
                'description' => 'Exercise or fitness activity',
                'icon' => 'o-bolt',
            ],
            'user_profile' => [
                'display_name' => 'User Profile',
                'description' => 'User account or profile',
                'icon' => 'o-user',
            ],
        ];
    }

    /**
     * Main plugin handler - process the event and create blocks
     *
     * ✅ ALWAYS use $this->event->createBlock() for creating blocks
     * ❌ NEVER use $this->event->blocks()->create() - it creates duplicates!
     */
    public function handle(): void
    {
        // Example: Handle different action types
        switch ($this->event->action) {
            case 'activity_completed':
                $this->handleActivityCompleted();
                break;
            case 'metric_recorded':
                $this->handleMetricRecorded();
                break;
            default:
                // Log unknown action or skip
                logger()->warning("Unknown action for ExamplePlugin: {$this->event->action}");
        }
    }

    /**
     * Optional: Get supported event types for filtering
     */
    public function getSupportedEventTypes(): array
    {
        return [
            'activity_completed',
            'metric_recorded',
        ];
    }

    /**
     * Optional: Validate event data before processing
     */
    public function canHandle(): bool
    {
        // Check if this event type is supported
        if (! in_array($this->event->action, $this->getSupportedEventTypes())) {
            return false;
        }

        // Check if required data is present
        if (empty($this->event->metadata)) {
            return false;
        }

        return true;
    }

    /**
     * Handle activity completion events
     */
    private function handleActivityCompleted(): void
    {
        // Get data from event metadata
        $activityData = $this->event->metadata['activity'] ?? [];
        $activityType = $activityData['type'] ?? 'unknown';

        // ✅ CORRECT: Use createBlock() to prevent duplicates
        $this->event->createBlock([
            'title' => 'Activity Summary',
            'block_type' => 'activity',
            'value' => $activityData['duration'] ?? null,
            'value_unit' => 'minutes',
            'metadata' => [
                'activity_type' => $activityType,
                'completion_date' => $activityData['completed_at'] ?? null,
            ],
        ]);

        // Create additional blocks if needed
        if (isset($activityData['calories_burned'])) {
            $this->event->createBlock([
                'title' => 'Calories Burned',
                'block_type' => 'calories',
                'value' => $activityData['calories_burned'],
                'value_unit' => 'kcal',
                'metadata' => [
                    'activity_type' => $activityType,
                ],
            ]);
        }

        if (isset($activityData['distance'])) {
            $this->event->createBlock([
                'title' => 'Distance',
                'block_type' => 'distance',
                'value' => $activityData['distance'],
                'value_unit' => $activityData['distance_unit'] ?? 'km',
            ]);
        }
    }

    /**
     * Handle metric recording events
     */
    private function handleMetricRecorded(): void
    {
        $metricData = $this->event->metadata['metric'] ?? [];
        $metricName = $metricData['name'] ?? 'Unknown Metric';

        // ✅ CORRECT: Use createBlock() to prevent duplicates
        $this->event->createBlock([
            'title' => $metricName,
            'block_type' => 'metric',
            'value' => $metricData['value'] ?? null,
            'value_unit' => $metricData['unit'] ?? null,
            'metadata' => [
                'metric_category' => $metricData['category'] ?? null,
                'recorded_at' => $metricData['timestamp'] ?? null,
            ],
        ]);
    }

    /**
     * Optional: Clean up or transform data before creating blocks
     */
    private function sanitizeValue($value): mixed
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * Optional: Helper method to create multiple blocks at once
     */
    private function createBlocks(array $blocksData): Collection
    {
        $blocks = collect();

        foreach ($blocksData as $blockData) {
            // ✅ CORRECT: Always use createBlock()
            $block = $this->event->createBlock($blockData);
            $blocks->push($block);
        }

        return $blocks;
    }

    /**
     * Optional: Handle errors gracefully
     */
    private function handleError(Throwable $exception, string $context = ''): void
    {
        logger()->error("ExamplePlugin error: {$exception->getMessage()}", [
            'context' => $context,
            'event_id' => $this->event->id,
            'exception' => $exception,
        ]);
    }
}

/*
 * IMPORTANT REMINDERS FOR PLUGIN DEVELOPMENT:
 *
 * 1. ✅ ALWAYS use $this->event->createBlock() to create blocks
 * 2. ❌ NEVER use $this->event->blocks()->create() - it creates duplicates!
 * 3. The createBlock() method automatically handles uniqueness based on:
 *    - event_id + title + block_type combination
 * 4. If a block with the same title and block_type exists for the event,
 *    it will be updated instead of creating a duplicate
 * 5. Use meaningful block_type values to categorize your blocks
 * 6. Include relevant metadata to provide context for the blocks
 * 7. Handle errors gracefully and log issues for debugging
 * 8. Test your plugin thoroughly to ensure no duplicate blocks are created
 *
 * BLOCK DATA STRUCTURE:
 *
 * Required:
 * - title (string): Display name for the block
 *
 * Optional:
 * - block_type (string): Category/type of block for organization
 * - value (mixed): Numeric or other measurable value
 * - value_multiplier (int): Multiplier for the value (default: 1)
 * - value_unit (string): Unit of measurement (e.g., 'bpm', 'kcal', 'minutes')
 * - metadata (array): Additional structured data
 * - url (string): Related URL or link
 * - media_url (string): Image or media URL
 * - embeddings (mixed): Vector embeddings for AI/ML
 * - time (string): Specific timestamp for the block (defaults to event time)
 *
 * EXAMPLE BLOCK CREATION:
 *
 * $this->event->createBlock([
 *     'title' => 'Heart Rate',
 *     'block_type' => 'biometric',
 *     'value' => 72,
 *     'value_unit' => 'bpm',
 *     'metadata' => [
 *         'measurement_type' => 'resting',
 *         'device' => 'smartwatch',
 *     ],
 * ]);
 */
