<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add composite index for event listing queries (integration + time ordering)
        Schema::table('events', function (Blueprint $table) {
            $table->index(['integration_id', 'time'], 'events_integration_time_idx');
            $table->index(['integration_id', 'deleted_at'], 'events_integration_deleted_idx');
            $table->index(['service', 'domain', 'action'], 'events_service_domain_action_idx');
            $table->index(['time', 'deleted_at'], 'events_time_deleted_idx');
        });

        // Add index for blocks event lookups
        Schema::table('blocks', function (Blueprint $table) {
            $table->index(['event_id', 'deleted_at'], 'blocks_event_deleted_idx');
        });

        // Add index for integrations user lookups
        Schema::table('integrations', function (Blueprint $table) {
            $table->index(['user_id', 'deleted_at'], 'integrations_user_deleted_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_integration_time_idx');
            $table->dropIndex('events_integration_deleted_idx');
            $table->dropIndex('events_service_domain_action_idx');
            $table->dropIndex('events_time_deleted_idx');
        });

        Schema::table('blocks', function (Blueprint $table) {
            $table->dropIndex('blocks_event_deleted_idx');
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->dropIndex('integrations_user_deleted_idx');
        });
    }
};
