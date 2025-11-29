<?php

namespace App\Services\TaskPipeline;

use Closure;
use Illuminate\Database\Eloquent\Model;
use App\Models\Event;
use App\Models\Block;
use App\Models\EventObject;
use App\Models\Integration;
use InvalidArgumentException;

class TaskDefinition
{
    public function __construct(
        public string $key,                          // Unique identifier (e.g., 'generate_embedding')
        public string $name,                         // Display name
        public string $description,                  // What it does
        public string $jobClass,                     // Job to dispatch
        public array $appliesTo,                     // ['event', 'block', 'object', 'integration']
        public array $conditions = [],               // Filtering conditions
        public array $dependencies = [],             // Task keys that must run first
        public string $queue = 'tasks',              // Queue name
        public int $priority = 50,                   // Execution priority (higher = first)
        public bool $runOnCreate = true,             // Run when item created
        public bool $runOnUpdate = false,            // Run when item updated
        public ?Closure $shouldRun = null,           // Custom condition callback
        public ?string $registeredBy = null,         // Plugin class that registered it
    ) {}

    /**
     * Check if this task is applicable to the given model
     */
    public function isApplicableTo(Model $model): bool
    {
        // Check model type
        if (!in_array($this->getModelType($model), $this->appliesTo)) {
            return false;
        }

        // Check conditions
        foreach ($this->conditions as $field => $value) {
            // Handle array of allowed values
            if (is_array($value)) {
                if (!in_array($model->$field, $value)) {
                    return false;
                }
            } else {
                // Single value match
                if ($model->$field !== $value) {
                    return false;
                }
            }
        }

        // Check custom condition callback
        if ($this->shouldRun && !($this->shouldRun)($model)) {
            return false;
        }

        return true;
    }

    /**
     * Get the model type string from a model instance
     */
    private function getModelType(Model $model): string
    {
        return match (get_class($model)) {
            Event::class => 'event',
            Block::class => 'block',
            EventObject::class => 'object',
            Integration::class => 'integration',
            default => throw new InvalidArgumentException('Unsupported model type: ' . get_class($model)),
        };
    }
}
