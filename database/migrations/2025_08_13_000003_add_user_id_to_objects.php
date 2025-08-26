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
            $table->uuid('user_id')->nullable()->after('time');
        });

        // Backfill user_id based on the owning integration's user_id
        DB::statement(
            'UPDATE objects o SET user_id = i.user_id FROM integrations i WHERE o.integration_id = i.id'
        );

        Schema::table('objects', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on(Schema::getConnection()->getTablePrefix() . 'users');
        });

        // Ensure user_id is required going forward
        DB::statement('ALTER TABLE objects ALTER COLUMN user_id SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('objects', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
