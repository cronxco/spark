<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Guard against duplicate constraint creation (PostgreSQL)
        $exists = false;
        try {
            $rows = DB::select(
                "select 1 from information_schema.table_constraints where table_name = 'events' and constraint_name = 'events_integration_source_unique' limit 1"
            );
            $exists = !empty($rows);
        } catch (\Throwable $e) {
            $exists = false;
        }

        if (! $exists) {
            Schema::table('events', function (Blueprint $table) {
                $table->unique(['integration_id', 'source_id'], 'events_integration_source_unique');
            });
        }
    }

    public function down(): void
    {
        // Drop only if exists
        $exists = false;
        try {
            $rows = DB::select(
                "select 1 from information_schema.table_constraints where table_name = 'events' and constraint_name = 'events_integration_source_unique' limit 1"
            );
            $exists = !empty($rows);
        } catch (\Throwable $e) {
            $exists = false;
        }

        if ($exists) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropUnique('events_integration_source_unique');
            });
        }
    }
};


