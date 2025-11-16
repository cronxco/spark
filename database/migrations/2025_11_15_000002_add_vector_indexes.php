<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        // Create HNSW indexes for fast approximate nearest neighbor search
        // Using cosine distance operator (<=>)
        // Only create if tables exist (handles fresh migrations)

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'embeddings')) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$prefix}events_embeddings_idx ON {$prefix}events USING hnsw (embeddings vector_cosine_ops)");
        }

        if (Schema::hasTable('blocks') && Schema::hasColumn('blocks', 'embeddings')) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$prefix}blocks_embeddings_idx ON {$prefix}blocks USING hnsw (embeddings vector_cosine_ops)");
        }

        if (Schema::hasTable('objects') && Schema::hasColumn('objects', 'embeddings')) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$prefix}objects_embeddings_idx ON {$prefix}objects USING hnsw (embeddings vector_cosine_ops)");
        }
    }

    public function down(): void
    {
        $prefix = DB::getTablePrefix();

        DB::statement("DROP INDEX IF EXISTS {$prefix}events_embeddings_idx");
        DB::statement("DROP INDEX IF EXISTS {$prefix}blocks_embeddings_idx");
        DB::statement("DROP INDEX IF EXISTS {$prefix}objects_embeddings_idx");
    }
};
