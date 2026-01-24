<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geocoding_cache', function (Blueprint $table) {
            $table->id();
            $table->string('address_hash', 64)->unique();
            $table->text('original_address');
            $table->text('formatted_address')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('source')->default('geoapify');
            $table->unsignedInteger('hit_count')->default(1);
            $table->timestamp('last_used_at');
            $table->timestamps();

            // Index for cleanup queries
            $table->index('last_used_at');
        });

        // Get table name with prefix
        $tableName = Schema::getConnection()->getTablePrefix().'geocoding_cache';

        // Add geography column using raw SQL (PostGIS type)
        DB::statement("ALTER TABLE {$tableName} ADD COLUMN location GEOGRAPHY(POINT, 4326)");

        // Add spatial index for performance
        DB::statement("CREATE INDEX {$tableName}_location_idx ON {$tableName} USING GIST(location)");
    }

    public function down(): void
    {
        // Get table name with prefix
        $tableName = Schema::getConnection()->getTablePrefix().'geocoding_cache';

        // Drop spatial index
        DB::statement("DROP INDEX IF EXISTS {$tableName}_location_idx");

        // Drop geography column
        DB::statement("ALTER TABLE {$tableName} DROP COLUMN IF EXISTS location");

        Schema::dropIfExists('geocoding_cache');
    }
};
