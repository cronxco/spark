<?php

namespace App\Integrations\Outline;

use App\Integrations\Base\ManualPlugin;

class OutlinePlugin extends ManualPlugin
{
    public static function getServiceType(): string
    {
        return 'apikey';
    }

    public static function getIdentifier(): string
    {
        return 'outline';
    }

    public static function getDisplayName(): string
    {
        return 'Outline';
    }

    public static function getDescription(): string
    {
        return 'Sync collections and documents from Outline, generate Day Notes, and extract tasks.';
    }

    public static function getConfigurationSchema($instanceType = null): array
    {
        $baseSchema = [
            'api_url' => [
                'type' => 'string',
                'label' => 'API URL',
                'required' => true,
                'default' => config('services.outline.url'),
            ],
            'access_token' => [
                'type' => 'string',
                'label' => 'Access Token',
                'required' => true,
                'default' => config('services.outline.access_token'),
            ],
            'daynotes_collection_id' => [
                'type' => 'string',
                'label' => 'Day Notes Collection ID',
                'required' => true,
                'default' => config('services.outline.daynotes_collection_id'),
            ],
            'migration_status' => [
                'type' => 'string',
                'label' => 'Migration Status',
                'required' => false,
                'default' => 'not_started',
                'options' => [
                    'not_started' => 'Not Started',
                    'started' => 'In Progress',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ],
            ],
            'migration_started_at' => [
                'type' => 'string',
                'label' => 'Migration Started At',
                'required' => false,
            ],
            'migration_completed_at' => [
                'type' => 'string',
                'label' => 'Migration Completed At',
                'required' => false,
            ],
        ];

        if ($instanceType === 'recent_daynotes') {
            return array_merge($baseSchema, [
                'update_frequency_minutes' => [
                    'type' => 'integer',
                    'label' => 'Update Frequency (minutes)',
                    'required' => true,
                    'min' => 5,
                    'max' => 60,
                    'default' => 15,
                    'description' => 'How often to sync recent day notes (5-60 minutes)',
                ],
                'document_limit' => [
                    'type' => 'integer',
                    'label' => 'Document Limit',
                    'required' => false,
                    'min' => 1,
                    'max' => 20,
                    'default' => 5,
                    'description' => 'Number of most recent day notes to sync',
                ],
            ]);
        }

        if ($instanceType === 'recent_documents') {
            return array_merge($baseSchema, [
                'update_frequency_minutes' => [
                    'type' => 'integer',
                    'label' => 'Update Frequency (minutes)',
                    'required' => true,
                    'min' => 60,
                    'max' => 1440,
                    'default' => 120,
                    'description' => 'How often to sync recent documents (1-24 hours)',
                ],
                'document_limit' => [
                    'type' => 'integer',
                    'label' => 'Document Limit',
                    'required' => false,
                    'min' => 1,
                    'max' => 50,
                    'default' => 10,
                    'description' => 'Number of most recent documents to sync',
                ],
            ]);
        }

        // Legacy schema for backward compatibility
        return array_merge($baseSchema, [
            'poll_interval_minutes' => [
                'type' => 'integer',
                'label' => 'Polling Interval (minutes)',
                'required' => true,
                'min' => 1,
                'default' => (int) (config('services.outline.poll_interval_minutes') ?? 15),
            ],
            'since_cursor' => [
                'type' => 'string',
                'label' => 'Since Cursor (updatedAt ISO8601)',
                'required' => false,
            ],
            'backfill_years' => [
                'type' => 'integer',
                'label' => 'Backfill Years',
                'required' => false,
                'default' => 3,
            ],
        ]);
    }

    public static function getInstanceTypes(): array
    {
        return [
            'recent_daynotes' => [
                'label' => 'Recent Day Notes',
                'schema' => self::getConfigurationSchema('recent_daynotes'),
                'description' => 'Syncs the 5 most recently edited documents from the day notes collection every 15 minutes',
            ],
            'recent_documents' => [
                'label' => 'Recent Documents',
                'schema' => self::getConfigurationSchema('recent_documents'),
                'description' => 'Syncs the 10 most recently updated documents across all collections every 2 hours',
            ],
            'task' => [
                'label' => 'Outline Task',
                'schema' => array_merge(self::getConfigurationSchema(), [
                    // Task execution controls
                    'task_mode' => [
                        'type' => 'string',
                        'label' => 'Task mode',
                        'required' => true,
                        'options' => [
                            'artisan' => 'Run Artisan command',
                            'job' => 'Dispatch Job class',
                        ],
                        'default' => 'job',
                    ],
                    'task_job_class' => [
                        'type' => 'string',
                        'label' => 'Job class (FQCN)',
                        'required' => false,
                    ],
                    'task_payload' => [
                        'type' => 'array',
                        'label' => 'Payload (JSON key:value)',
                        'required' => false,
                    ],
                    'task_queue' => [
                        'type' => 'string',
                        'label' => 'Queue name',
                        'required' => false,
                        'default' => 'pull',
                    ],
                    'use_schedule' => [
                        'type' => 'integer',
                        'label' => 'Use schedule (0/1)',
                        'required' => false,
                        'default' => 0,
                    ],
                    'schedule_times' => [
                        'type' => 'array',
                        'label' => 'Cron times',
                        'required' => false,
                    ],
                    'schedule_timezone' => [
                        'type' => 'string',
                        'label' => 'Timezone',
                        'required' => false,
                        'default' => 'UTC',
                    ],
                    'paused' => [
                        'type' => 'integer',
                        'label' => 'Paused (0/1)',
                        'required' => false,
                        'default' => 0,
                    ],
                ]),
                'presets' => [
                    [
                        'key' => 'pin_today',
                        'name' => 'Pin Day Note',
                        'configuration' => [
                            'task_mode' => 'job',
                            'task_job_class' => 'App\\Jobs\\Outline\\PinTodayDayNote',
                            'task_payload' => [],
                            'task_queue' => 'pull',
                            'use_schedule' => 1,
                            'schedule_times' => ['00:05'],
                            'schedule_timezone' => 'UTC',
                            'paused' => 0,
                        ],
                    ],
                    [
                        'key' => 'generate_year',
                        'name' => 'Generate Day Notes for Year',
                        'configuration' => [
                            'task_mode' => 'job',
                            'task_job_class' => 'App\\Jobs\\Outline\\GenerateDayNotes',
                            'task_payload' => ['year' => (int) date('Y')],
                            'task_queue' => 'pull',
                            'use_schedule' => 0,
                            'schedule_times' => [],
                            'schedule_timezone' => 'UTC',
                            'paused' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'o-document-text';
    }

    public static function getAccentColor(): string
    {
        return 'info';
    }

    public static function getDomain(): string
    {
        return 'knowledge';
    }

    public static function getActionTypes(): array
    {
        return [
            'had_day_note' => [
                'icon' => 'o-calendar',
                'display_name' => 'Had Day Note',
                'description' => 'A Day Note existed for the day',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'created' => [
                'icon' => 'o-plus-circle',
                'display_name' => 'Created Document',
                'description' => 'An Outline document was created',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'day_task' => [
                'icon' => 'o-check-circle',
                'display_name' => 'Day Task',
                'description' => 'A task extracted from a Day Note',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'doc_task' => [
                'icon' => 'o-check-circle',
                'display_name' => 'Document Task',
                'description' => 'A task extracted from a document',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'outline_collection' => [
                'icon' => 'o-rectangle-stack',
                'display_name' => 'Outline Collection',
                'description' => 'An Outline collection',
                'hidden' => false,
            ],
            'outline_document' => [
                'icon' => 'o-document',
                'display_name' => 'Outline Document',
                'description' => 'An Outline document',
                'hidden' => false,
            ],
            'outline_user' => [
                'icon' => 'o-user-circle',
                'display_name' => 'Outline User',
                'description' => 'An Outline user',
                'hidden' => true,
            ],
        ];
    }
}
