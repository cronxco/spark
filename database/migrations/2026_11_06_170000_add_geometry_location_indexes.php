<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bounding-box scopes compare `location::geometry` against a planar envelope
 * (a geography envelope fails on world-sized boxes and bends on wide ones).
 * The existing GIST index is on the geography column, so it can't serve that
 * expression; these partial expression indexes do.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['events', 'objects'] as $table) {
            $name = Schema::getConnection()->getTablePrefix() . $table;

            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$name}_location_geom_idx ON {$name} USING GIST ((location::geometry)) WHERE location IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        foreach (['events', 'objects'] as $table) {
            $name = Schema::getConnection()->getTablePrefix() . $table;

            DB::statement("DROP INDEX IF EXISTS {$name}_location_geom_idx");
        }
    }
};
