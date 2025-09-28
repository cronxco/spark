<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First, remove any existing duplicate blocks before adding the constraint
        DB::statement("
            DELETE FROM blocks b1
            USING blocks b2
            WHERE b1.id > b2.id
            AND b1.event_id = b2.event_id
            AND b1.title = b2.title
            AND COALESCE(b1.block_type, '') = COALESCE(b2.block_type, '')
            AND b1.deleted_at IS NULL
            AND b2.deleted_at IS NULL
        ");

        // Add unique constraint to prevent duplicate blocks per event
        Schema::table('blocks', function (Blueprint $table) {
            $table->unique(['event_id', 'title', 'block_type'], 'unique_event_title_block_type');
        });
    }

    public function down(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            $table->dropUnique('unique_event_title_block_type');
        });
    }
};
