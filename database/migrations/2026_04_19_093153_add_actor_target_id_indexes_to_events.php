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

        // Slim single-column partial indexes for COUNT(*) WHERE actor_id/target_id = ?.
        // Composite (actor_id, time) and (target_id, time) indexes already exist for
        // ranged scans, but Postgres prefers a narrower index for pure COUNT because
        // it touches fewer pages — Supabase index advisor reported a ~1700x cost drop.
        $this->safeStatement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS events_actor_id_idx '
            . 'ON "' . $prefix . 'events" (actor_id) '
            . 'WHERE actor_id IS NOT NULL AND deleted_at IS NULL'
        );

        $this->safeStatement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS events_target_id_idx '
            . 'ON "' . $prefix . 'events" (target_id) '
            . 'WHERE target_id IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->safeStatement('DROP INDEX CONCURRENTLY IF EXISTS events_actor_id_idx');
        $this->safeStatement('DROP INDEX CONCURRENTLY IF EXISTS events_target_id_idx');
    }

    private function safeStatement(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (\Throwable $e) {
            report($e);
            throw $e;
        }
    }
    }
};
