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
        // Update events table: TEXT -> vector(1536)
        DB::statement('ALTER TABLE events ALTER COLUMN embeddings TYPE vector(1536) USING NULL');

        // Update blocks table: TEXT -> vector(1536)
        DB::statement('ALTER TABLE blocks ALTER COLUMN embeddings TYPE vector(1536) USING NULL');

        // Update objects table: vector(3) -> vector(1536)
        DB::statement('ALTER TABLE objects ALTER COLUMN embeddings TYPE vector(1536) USING NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert events table: vector(1536) -> TEXT
        DB::statement('ALTER TABLE events ALTER COLUMN embeddings TYPE TEXT USING NULL');

        // Revert blocks table: vector(1536) -> TEXT
        DB::statement('ALTER TABLE blocks ALTER COLUMN embeddings TYPE TEXT USING NULL');

        // Revert objects table: vector(1536) -> vector(3)
        DB::statement('ALTER TABLE objects ALTER COLUMN embeddings TYPE vector(3) USING NULL');
    }
};
