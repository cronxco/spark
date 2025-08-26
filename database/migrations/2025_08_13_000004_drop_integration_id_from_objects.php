<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objects', function (Blueprint $table) {
            // Drop foreign key if exists, then column
            try {
                $table->dropForeign(['integration_id']);
            } catch (\Throwable $e) {
                // ignore if not present
            }
            if (Schema::hasColumn('objects', 'integration_id')) {
                $table->dropColumn('integration_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('objects', function (Blueprint $table) {
            if (! Schema::hasColumn('objects', 'integration_id')) {
                $table->uuid('integration_id')->nullable();
            }
        });
    }
};
