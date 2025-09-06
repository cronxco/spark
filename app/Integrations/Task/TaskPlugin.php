<?php

namespace App\Integrations\Task;

use App\Integrations\Base\ManualPlugin;

class TaskPlugin extends ManualPlugin
{
    public static function getConfigurationSchema(): array
    {
        // No plugin-level config; all config lives on instances
        return [];
    }

    public static function getIdentifier(): string
    {
        return 'task';
    }

    public static function getDisplayName(): string
    {
        return 'Task';
    }

    public static function getDescription(): string
    {
        return 'Run scheduled jobs or artisan commands not tied to a specific external service.';
    }

    public static function getIcon(): string
    {
        return 'fas.clock';
    }

    public static function getAccentColor(): string
    {
        return 'info';
    }

    public static function getDomain(): string
    {
        return 'online';
    }

    public static function getActionTypes(): array
    {
        return [];
    }

    public static function getBlockTypes(): array
    {
        return [];
    }

    public static function getObjectTypes(): array
    {
        return [];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'task' => [
                'label' => 'Task',
                'schema' => [
                    'task_mode' => [
                        'type' => 'string',
                        'label' => 'Task mode',
                        'required' => true,
                        'options' => [
                            'artisan' => 'Run Artisan command',
                            'job' => 'Dispatch Job class',
                        ],
                        'default' => 'artisan',
                    ],
                    'task_command' => [
                        'type' => 'string',
                        'label' => 'Artisan command',
                        'required' => false,
                        'description' => 'e.g. queue:prune-batches',
                    ],
                    'task_job_class' => [
                        'type' => 'string',
                        'label' => 'Job class (FQCN)',
                        'required' => false,
                        'description' => 'e.g. App\\Jobs\\ReindexSearch',
                    ],
                    'task_payload' => [
                        'type' => 'array',
                        'label' => 'Payload (JSON array of key:value pairs)',
                        'required' => false,
                    ],
                    'task_queue' => [
                        'type' => 'string',
                        'label' => 'Queue name',
                        'required' => false,
                        'default' => 'pull',
                    ],
                    'paused' => [
                        'type' => 'integer',
                        'label' => 'Paused (0/1)',
                        'required' => false,
                    ],
                    'use_schedule' => [
                        'type' => 'integer',
                        'label' => 'Use schedule (0/1)',
                        'required' => false,
                        'default' => 1,
                    ],
                    'schedule_times' => [
                        'type' => 'array',
                        'label' => 'Schedule times (HH:mm, comma-separated)',
                        'required' => false,
                    ],
                    'schedule_timezone' => [
                        'type' => 'string',
                        'label' => 'Schedule timezone',
                        'required' => false,
                        'default' => 'UTC',
                    ],
                    'update_frequency_minutes' => [
                        'type' => 'integer',
                        'label' => 'Fallback frequency (minutes)',
                        'required' => false,
                        'min' => 1,
                        'default' => 60,
                    ],
                ],
            ],
        ];
    }
}
