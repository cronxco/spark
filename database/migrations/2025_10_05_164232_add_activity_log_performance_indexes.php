<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            // Add indexes for filter columns that are currently missing
            $table->index('event', 'activity_log_event_idx');
            $table->index('subject_type', 'activity_log_subject_type_idx');

            // Add composite index for the main query (created_at DESC with filters)
            $table->index(['created_at', 'log_name'], 'activity_log_created_log_idx');
            $table->index(['created_at', 'event'], 'activity_log_created_event_idx');
            $table->index(['created_at', 'subject_type'], 'activity_log_created_subject_idx');

            // Add indexes for morphed relationships to improve eager loading
            $table->index(['subject_type', 'subject_id'], 'activity_log_subject_morph_idx');
            $table->index(['causer_type', 'causer_id'], 'activity_log_causer_morph_idx');
        });

        // Add PostgreSQL-specific optimizations if using PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            $connection = Schema::connection(config('activitylog.database_connection'));
            $tableName = $connection->getConnection()->getTablePrefix() . config('activitylog.table_name');

            // Create expression indexes for case-insensitive searches
            DB::connection(config('activitylog.database_connection'))->statement("CREATE INDEX activity_log_description_lower_idx ON {$tableName} (LOWER(description))");
            DB::connection(config('activitylog.database_connection'))->statement("CREATE INDEX activity_log_log_name_lower_idx ON {$tableName} (LOWER(log_name))");
            DB::connection(config('activitylog.database_connection'))->statement("CREATE INDEX activity_log_event_lower_idx ON {$tableName} (LOWER(event))");
            DB::connection(config('activitylog.database_connection'))->statement("CREATE INDEX activity_log_subject_type_lower_idx ON {$tableName} (LOWER(subject_type))");
        }
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            // Drop regular indexes
            $table->dropIndex('activity_log_event_idx');
            $table->dropIndex('activity_log_subject_type_idx');
            $table->dropIndex('activity_log_created_log_idx');
            $table->dropIndex('activity_log_created_event_idx');
            $table->dropIndex('activity_log_created_subject_idx');
            $table->dropIndex('activity_log_subject_morph_idx');
            $table->dropIndex('activity_log_causer_morph_idx');
        });

        // Drop PostgreSQL-specific indexes
        if (DB::getDriverName() === 'pgsql') {
            DB::connection(config('activitylog.database_connection'))->statement('DROP INDEX IF EXISTS activity_log_description_lower_idx');
            DB::connection(config('activitylog.database_connection'))->statement('DROP INDEX IF EXISTS activity_log_log_name_lower_idx');
            DB::connection(config('activitylog.database_connection'))->statement('DROP INDEX IF EXISTS activity_log_event_lower_idx');
            DB::connection(config('activitylog.database_connection'))->statement('DROP INDEX IF EXISTS activity_log_subject_type_lower_idx');
        }
    }
};
