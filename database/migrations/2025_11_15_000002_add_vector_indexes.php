<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create HNSW indexes for fast approximate nearest neighbor search
        // Using cosine distance operator (<=>)

        DB::statement('CREATE INDEX events_embeddings_idx ON events USING hnsw (embeddings vector_cosine_ops)');
        DB::statement('CREATE INDEX blocks_embeddings_idx ON blocks USING hnsw (embeddings vector_cosine_ops)');
        DB::statement('CREATE INDEX objects_embeddings_idx ON objects USING hnsw (embeddings vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS events_embeddings_idx');
        DB::statement('DROP INDEX IF EXISTS blocks_embeddings_idx');
        DB::statement('DROP INDEX IF EXISTS objects_embeddings_idx');
    }
};
