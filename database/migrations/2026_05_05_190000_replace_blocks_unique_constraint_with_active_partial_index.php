<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();
        $table = $prefix . 'blocks';
        $index = $prefix . 'unique_active_event_title_block_type';

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "' . $table . '" DROP CONSTRAINT IF EXISTS "unique_event_title_block_type"');
            DB::statement('DROP INDEX IF EXISTS "' . $index . '"');
            DB::statement(
                'CREATE UNIQUE INDEX "' . $index . '" ON "' . $table . '" ("event_id", "title", "block_type") WHERE "deleted_at" IS NULL'
            );

            return;
        }

        Schema::table('blocks', function ($table): void {
            $table->dropUnique('unique_event_title_block_type');
        });

        DB::statement(
            'CREATE UNIQUE INDEX ' . $index . ' ON ' . $table . ' (event_id, title, block_type) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        $prefix = DB::getTablePrefix();
        $table = $prefix . 'blocks';
        $index = $prefix . 'unique_active_event_title_block_type';

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS "' . $index . '"');

            Schema::table('blocks', function ($table): void {
                $table->unique(['event_id', 'title', 'block_type'], 'unique_event_title_block_type');
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS ' . $index);

        Schema::table('blocks', function ($table): void {
            $table->unique(['event_id', 'title', 'block_type'], 'unique_event_title_block_type');
        });
    }
};
