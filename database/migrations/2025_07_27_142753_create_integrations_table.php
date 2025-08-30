<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->uuid('integration_group_id')->nullable();
            $table->text('service')->nullable();
            $table->text('name')->nullable();
            $table->text('account_id')->nullable();
            $table->text('instance_type')->nullable();
            $table->jsonb('configuration')->nullable();
            $table->integer('update_frequency_minutes')->default(15);
            $table->timestampTz('last_triggered_at')->nullable();
            $table->timestampTz('last_successful_update_at')->nullable();
            $table->string('migration_batch_id', 36)->nullable();
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('deleted_at')->nullable();
            // Indexes for performance
            $table->index('user_id');
            $table->index('integration_group_id');
            $table->index('migration_batch_id', Schema::getConnection()->getTablePrefix() . 'integrations_migration_batch_id_index');
            // Foreign keys
            $table->foreign('user_id', Schema::getConnection()->getTablePrefix() . 'integrations_user_id_foreign')->references('id')->on('users');
            $table->foreign('integration_group_id', Schema::getConnection()->getTablePrefix() . 'integrations_integration_group_id_foreign')
                ->references('id')
                ->on('integration_groups')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
