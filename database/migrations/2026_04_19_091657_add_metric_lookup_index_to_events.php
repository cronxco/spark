<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $prefix = DB::getTablePrefix();

        if (! Schema::hasTable($prefix . 'events')) {
            return;
        }

        $this->safeStatement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS events_metric_lookup_idx '
            . 'ON "' . $prefix . 'events" (integration_id, service, action, value_unit) '
            . 'WHERE value IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->safeStatement('DROP INDEX CONCURRENTLY IF EXISTS events_metric_lookup_idx');
    }

    private function safeStatement(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (\Throwable $e) {
            // Swallow errors to avoid aborting entire migration.
        }
    }
};
