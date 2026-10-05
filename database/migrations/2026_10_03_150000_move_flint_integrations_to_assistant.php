<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decision D-F4: one Flint integration per user, with the plugin's `assistant`
 * instance type. A value-only change, with no schema change.
 *
 * Each user keeps one Flint row: their oldest `assistant` row, or failing that
 * their oldest Flint row, which becomes `assistant`. Digests and task history
 * on any other Flint row move onto it, and the emptied row is soft-deleted so
 * it can still be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        $userIds = DB::table('integrations')
            ->where('service', 'flint')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            DB::transaction(function () use ($userId): void {
                $rows = DB::table('integrations')
                    ->where('user_id', $userId)
                    ->where('service', 'flint')
                    ->whereNull('deleted_at')
                    ->orderByRaw("CASE WHEN instance_type = 'assistant' THEN 0 ELSE 1 END")
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->pluck('id');

                $canonical = $rows->shift();
                DB::table('integrations')->where('id', $canonical)->update(['instance_type' => 'assistant']);

                foreach ($rows as $duplicate) {
                    DB::table('events')->where('integration_id', $duplicate)->update(['integration_id' => $canonical]);

                    $canonicalTasks = DB::table('task_executions')
                        ->where('entity_type', 'integration')
                        ->where('entity_id', $canonical)
                        ->pluck('task_key');
                    DB::table('task_executions')
                        ->where('entity_type', 'integration')
                        ->where('entity_id', $duplicate)
                        ->whereNotIn('task_key', $canonicalTasks)
                        ->update(['entity_id' => $canonical]);

                    DB::table('integrations')->where('id', $duplicate)->update(['deleted_at' => now()]);
                }
            });
        }
    }

    public function down(): void
    {
        // The merge is not reversed: the moved digests carry no record of
        // which row they came from. Soft-deleted rows can be restored by hand.
    }
};
