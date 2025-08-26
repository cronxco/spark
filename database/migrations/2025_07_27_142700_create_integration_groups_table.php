<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateIntegrationGroupsTable extends Migration
{
    public function up(): void
    {
        Schema::create('integration_groups', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->text('service');
            $table->text('account_id')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('expiry')->nullable();
            $table->timestampTz('refresh_expiry')->nullable();
            $table->jsonb('auth_metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('deleted_at')->nullable();
            // Indexes for performance
            $table->index('user_id');
            $table->index('service');
            $table->index('account_id');
            // If a user is deleted, remove their integration groups
            $table->foreign('user_id')
                ->references('id')
                ->on(Schema::getConnection()->getTablePrefix() . 'users')
                ->onDelete('cascade');
        });
        // Partial unique index (user_id, service, account_id) where account_id IS NOT NULL
        $tableName = Schema::getConnection()->getTablePrefix() . 'integration_groups';
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS integration_groups_user_service_account_unique ON {$tableName} (user_id, service, account_id) WHERE account_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_groups');
    }
}
