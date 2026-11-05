<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objects', function (Blueprint $table) {
            $table->text('location_address')->nullable()->after('metadata');
            $table->timestamp('location_geocoded_at')->nullable()->after('location_address');
            $table->string('location_source')->nullable()->after('location_geocoded_at');
        });

        // Get table name with prefix
        $tableName = Schema::getConnection()->getTablePrefix() . 'objects';

        // Add geography column using raw SQL (PostGIS type)
        DB::statement("ALTER TABLE {$tableName} ADD COLUMN location GEOGRAPHY(POINT, 4326)");

        // Add spatial index for performance
        DB::statement("CREATE INDEX {$tableName}_location_idx ON {$tableName} USING GIST(location)");
    }

    public function down(): void
    {
        // Get table name with prefix
        $tableName = Schema::getConnection()->getTablePrefix() . 'objects';

        // Drop spatial index
        DB::statement("DROP INDEX IF EXISTS {$tableName}_location_idx");

        // Drop geography column
        DB::statement("ALTER TABLE {$tableName} DROP COLUMN IF EXISTS location");

        Schema::table('objects', function (Blueprint $table) {
            $table->dropColumn(['location_address', 'location_geocoded_at', 'location_source']);
        });
    }
};
