<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->text('migration_batch_id')->nullable()->after('last_successful_update_at');
            $table->index('migration_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropIndex(['migration_batch_id']);
            $table->dropColumn('migration_batch_id');
        });
    }
};


