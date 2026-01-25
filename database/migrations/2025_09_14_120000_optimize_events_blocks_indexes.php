<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Use raw SQL to create concurrent indexes where supported (Postgres)
        // Guard: Only attempt if using PostgreSQL
        $driver = DB::getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        // Events: BRIN on time for large append-only scans
        if (Schema::hasTable(DB::getTablePrefix() . 'events')) {
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS brin_events_time ON "' . DB::getTablePrefix() . 'events" USING BRIN (time) WITH (pages_per_range=128)');
        }

        // Events: composite indexes commonly used in queries
        if (Schema::hasTable(DB::getTablePrefix() . 'events')) {
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_integration_time ON "' . DB::getTablePrefix() . 'events" (integration_id, time)');
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_service_domain_time ON "' . DB::getTablePrefix() . 'events" (service, domain, time)');
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_actor_time ON "' . DB::getTablePrefix() . 'events" (actor_id, time)');
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_target_time ON "' . DB::getTablePrefix() . 'events" (target_id, time)');
        }

        // Optional: denormalized user_id index if column exists
        try {
            if (Schema::hasColumn(DB::getTablePrefix() . 'events', 'user_id')) {
                $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_user_time ON "' . DB::getTablePrefix() . 'events" (user_id, time)');
            }
        } catch (\Throwable $e) {
            // ignore if column doesn't exist
        }

        // Blocks: indexes
        if (Schema::hasTable(DB::getTablePrefix() . 'blocks')) {
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_blocks_event_id ON "' . DB::getTablePrefix() . 'blocks" (event_id)');
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS brin_blocks_time ON "' . DB::getTablePrefix() . 'blocks" USING BRIN (time) WITH (pages_per_range=128)');
        }

        // Objects: unique identity for upserts (ignoring soft-deleted)
        if (Schema::hasTable(DB::getTablePrefix() . 'objects')) {
            $this->safeStatement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uniq_objects_identity ON "' . DB::getTablePrefix() . 'objects" (user_id, concept, type, title) WHERE deleted_at IS NULL');
        }

        // JSON expression indexes used by finance paths
        if (Schema::hasTable(DB::getTablePrefix() . 'objects')) {
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_objects_metadata_pot_id ON "' . DB::getTablePrefix() . "objects\" ((metadata->>'pot_id'))");
        }
        if (Schema::hasTable(DB::getTablePrefix() . 'events')) {
            $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_metadata_merchant ON "' . DB::getTablePrefix() . "events\" ((event_metadata->>'merchant_id'))");
        }

        // Optional broad GIN indexes on JSON for flexible filters
        try {
            if (Schema::hasTable(DB::getTablePrefix() . 'objects')) {
                // Use default jsonb_ops to maximize compatibility
                $this->safeStatement('CREATE INDEX CONCURRENTLY IF NOT EXISTS gin_objects_metadata ON "' . DB::getTablePrefix() . 'objects" USING GIN (metadata)');
            }
        } catch (\Throwable $e) {
            // Older PG versions may not support jsonb_path_ops; ignore
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        // Drop indexes if they exist
        $prefix = DB::getTablePrefix();
        $this->safeStatement('DROP INDEX IF EXISTS brin_events_time');
        $this->safeStatement('DROP INDEX IF EXISTS idx_events_integration_time');
        $this->safeStatement('DROP INDEX IF EXISTS idx_events_service_domain_time');
        $this->safeStatement('DROP INDEX IF EXISTS idx_events_actor_time');
        $this->safeStatement('DROP INDEX IF EXISTS idx_events_target_time');
        $this->safeStatement('DROP INDEX IF EXISTS idx_events_user_time');

        $this->safeStatement('DROP INDEX IF EXISTS idx_blocks_event_id');
        $this->safeStatement('DROP INDEX IF EXISTS brin_blocks_time');

        $this->safeStatement('DROP INDEX IF EXISTS uniq_objects_identity');
        $this->safeStatement('DROP INDEX IF EXISTS idx_objects_metadata_pot_id');
        $this->safeStatement('DROP INDEX IF EXISTS idx_events_metadata_merchant');
        $this->safeStatement('DROP INDEX IF EXISTS gin_objects_metadata');
    }

    private function safeStatement(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (\Throwable $e) {
            // Swallow errors to avoid aborting entire migration; log is optional.
        }
    }
};
