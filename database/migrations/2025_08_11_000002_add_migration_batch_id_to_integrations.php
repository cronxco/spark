<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            // Use fixed length UUID-friendly string; avoid after() for cross-DB compatibility
            $table->string('migration_batch_id', 36)->nullable();
            $table->index('migration_batch_id', 'integrations_migration_batch_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropIndex('integrations_migration_batch_id_index');
            $table->dropColumn('migration_batch_id');
        });
    }
};
