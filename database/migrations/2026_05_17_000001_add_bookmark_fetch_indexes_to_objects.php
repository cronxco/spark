<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes supporting the bookmarks/Fetch queries (FetchScheduledUrls,
 * savedPages, recurringUrls, discovery dedup). Expression indexes back the
 * metadata JSON predicates that are filtered/sorted on heavily.
 *
 * NOTE: review with EXPLAIN ANALYZE on production-scale data before relying
 * on these — the existing GIN index on metadata may already cover some of
 * the JSON predicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $t = DB::getTablePrefix() . 'objects';

        DB::statement('CREATE INDEX IF NOT EXISTS idx_objects_user_type_concept_active ON "' . $t . '" (user_id, type, concept) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_objects_meta_fetch_mode ON "' . $t . '" ((metadata->>\'fetch_mode\'))');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_objects_meta_subscription_source ON "' . $t . '" ((metadata->>\'subscription_source\'))');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_objects_meta_domain ON "' . $t . '" ((metadata->>\'domain\'))');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_objects_meta_fetch_integration_id ON "' . $t . '" ((metadata->>\'fetch_integration_id\'))');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'idx_objects_user_type_concept_active',
            'idx_objects_meta_fetch_mode',
            'idx_objects_meta_subscription_source',
            'idx_objects_meta_domain',
            'idx_objects_meta_fetch_integration_id',
        ] as $index) {
            DB::statement('DROP INDEX IF EXISTS "' . $index . '"');
        }
    }
};
