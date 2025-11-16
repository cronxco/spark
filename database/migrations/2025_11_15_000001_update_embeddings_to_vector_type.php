<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        // Only run if tables exist (handles fresh migrations)
        if (Schema::hasTable('events')) {
            DB::statement("ALTER TABLE {$prefix}events ALTER COLUMN embeddings TYPE vector(1536) USING NULL");
        }

        if (Schema::hasTable('blocks')) {
            DB::statement("ALTER TABLE {$prefix}blocks ALTER COLUMN embeddings TYPE vector(1536) USING NULL");
        }

        if (Schema::hasTable('objects')) {
            DB::statement("ALTER TABLE {$prefix}objects ALTER COLUMN embeddings TYPE vector(1536) USING NULL");
        }
    }

    public function down(): void
    {
        $prefix = DB::getTablePrefix();

        if (Schema::hasTable('events')) {
            DB::statement("ALTER TABLE {$prefix}events ALTER COLUMN embeddings TYPE TEXT USING NULL");
        }

        if (Schema::hasTable('blocks')) {
            DB::statement("ALTER TABLE {$prefix}blocks ALTER COLUMN embeddings TYPE TEXT USING NULL");
        }

        if (Schema::hasTable('objects')) {
            DB::statement("ALTER TABLE {$prefix}objects ALTER COLUMN embeddings TYPE vector(3) USING NULL");
        }
    }
};
