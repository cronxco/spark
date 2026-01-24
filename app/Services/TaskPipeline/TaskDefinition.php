<?php

namespace App\Services\TaskPipeline;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TaskDefinition
{
    private ?Closure $shouldRunCallback = null;

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
        ?Closure $shouldRun = null,                  // Custom condition callback
        public ?string $registeredBy = null,         // Plugin class that registered it
    ) {
        $this->shouldRunCallback = $shouldRun;
    }

    /**
     * Check if this task is applicable to the given model
     */
    public function isApplicableTo(Model $model): bool
    {
        // Check model type
        if (! in_array($this->getModelType($model), $this->appliesTo)) {
            return false;
        }

        // Check conditions
        foreach ($this->conditions as $field => $value) {
            // Handle array of allowed values
            if (is_array($value)) {
                if (! in_array($model->$field, $value)) {
                    return false;
                }
            } else {
                // Single value match
                if ($value !== $model->$field) {
                    return false;
                }
            }
        }

        // Check custom condition callback
        if ($this->shouldRunCallback && ! ($this->shouldRunCallback)($model)) {
            return false;
        }

        return true;
    }

    /**
     * Get the model type string from a model instance
     */
    private function getModelType(Model $model): string
    {
        // Check instanceof to handle inheritance (e.g., Place extends EventObject)
        if ($model instanceof Event) {
            return 'event';
        }

        if ($model instanceof Block) {
            return 'block';
        }

        if ($model instanceof EventObject) {
            return 'object';
        }

        if ($model instanceof Integration) {
            return 'integration';
        }

        throw new InvalidArgumentException('Unsupported model type: '.get_class($model));
    }

    /**
     * Prevent serialization of Closures
     */
    public function __sleep(): array
    {
        return [
            'key',
            'name',
            'description',
            'jobClass',
            'appliesTo',
            'conditions',
            'dependencies',
            'queue',
            'priority',
            'runOnCreate',
            'runOnUpdate',
            'registeredBy',
        ];
    }
}
