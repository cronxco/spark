<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard against duplicate index creation (PostgreSQL-aware)
        $exists = false;
        try {
            // Note: We need to manually handle the prefix for raw SQL statements
            $tableName = Schema::getConnection()->getTablePrefix() . 'events';
            $rows = DB::select(
                "select 1 from pg_indexes where tablename = '{$tableName}' and indexname = 'events_integration_source_unique' limit 1"
            );
            $exists = ! empty($rows);
        } catch (\Throwable $e) {
            $exists = false;
        }

        if (! $exists) {
            // Use IF NOT EXISTS to avoid race conditions; CONCURRENTLY requires no transaction
            try {
                // Note: We need to manually handle the prefix for raw SQL statements
                $tableName = Schema::getConnection()->getTablePrefix() . 'events';
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS events_integration_source_unique ON {$tableName} (integration_id, source_id)");
            } catch (\Throwable $e) {
                // Fallback to Schema builder if statement not supported
                Schema::table('events', function (Blueprint $table) {
                    $table->unique(['integration_id', 'source_id'], 'events_integration_source_unique');
                });
            }
        }
    }

    public function down(): void
    {
        // Safe drop for PostgreSQL (and no-op if missing)
        try {
            // Note: We need to manually handle the prefix for raw SQL statements
            $tableName = Schema::getConnection()->getTablePrefix() . 'events';
            DB::statement('DROP INDEX IF EXISTS events_integration_source_unique');
        } catch (\Throwable $e) {
            // Fallback: attempt Schema builder drop for other drivers
            Schema::table('events', function (Blueprint $table) {
                $table->dropUnique('events_integration_source_unique');
            });
        }
    }
};
