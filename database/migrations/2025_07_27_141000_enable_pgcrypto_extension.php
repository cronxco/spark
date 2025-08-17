<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure pgcrypto is available for gen_random_uuid()
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto";');
    }

    public function down(): void
    {
        // Do not drop the extension by default to avoid impacting other schemas
        // If you really need to remove it, uncomment the next line
        // DB::statement('DROP EXTENSION IF EXISTS "pgcrypto";');
    }
};
