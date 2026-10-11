<?php

return [
    'features' => [
        'reads' => env('KNOWLEDGE_TASK_READS_ENABLED', true),
        'writes' => env('KNOWLEDGE_TASK_WRITES_ENABLED', true),
        'projects' => env('KNOWLEDGE_PROJECTS_ENABLED', true),
        'incident_tasks' => env('KNOWLEDGE_INCIDENT_TASKS_ENABLED', true),
        'recurrence' => env('KNOWLEDGE_TASK_RECURRENCE_ENABLED', true),
        'reminders' => env('KNOWLEDGE_TASK_REMINDERS_ENABLED', true),
        'outline_sync' => env('KNOWLEDGE_OUTLINE_SYNC_ENABLED', true),
    ],
    // Enable only after the deployed Outline server rejects stale lastRevision writes.
    'outline_revision_writes_verified' => env('OUTLINE_REVISION_WRITES_VERIFIED', false),
];
