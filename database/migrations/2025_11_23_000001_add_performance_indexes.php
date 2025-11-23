<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add composite indexes for event listing queries
        $this->createIndexIfNotExists('events', ['integration_id', 'time'], 'events_integration_time_idx');
        $this->createIndexIfNotExists('events', ['integration_id', 'deleted_at'], 'events_integration_deleted_idx');
        $this->createIndexIfNotExists('events', ['service', 'domain', 'action'], 'events_service_domain_action_idx');
        $this->createIndexIfNotExists('events', ['time', 'deleted_at'], 'events_time_deleted_idx');

        // Add index for blocks event lookups
        $this->createIndexIfNotExists('blocks', ['event_id', 'deleted_at'], 'blocks_event_deleted_idx');

        // Add index for integrations user lookups
        $this->createIndexIfNotExists('integrations', ['user_id', 'deleted_at'], 'integrations_user_deleted_idx');

        // Add index for metric trends detection queries
        $this->createIndexIfNotExists(
            'metric_trends',
            ['metric_statistic_id', 'type', 'acknowledged_at', 'start_date'],
            'metric_trends_detection_idx'
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('events', 'events_integration_time_idx');
        $this->dropIndexIfExists('events', 'events_integration_deleted_idx');
        $this->dropIndexIfExists('events', 'events_service_domain_action_idx');
        $this->dropIndexIfExists('events', 'events_time_deleted_idx');
        $this->dropIndexIfExists('blocks', 'blocks_event_deleted_idx');
        $this->dropIndexIfExists('integrations', 'integrations_user_deleted_idx');
        $this->dropIndexIfExists('metric_trends', 'metric_trends_detection_idx');
    }

    /**
     * Check if an index exists in PostgreSQL
     */
    private function indexExists(string $indexName): bool
    {
        $result = DB::select('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$indexName]);

        return count($result) > 0;
    }

    /**
     * Safely create an index if it doesn't exist
     */
    private function createIndexIfNotExists(string $table, array $columns, string $indexName): void
    {
        if (! $this->indexExists($indexName)) {
            Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        }
    }

    /**
     * Safely drop an index if it exists
     */
    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($indexName)) {
            Schema::table($table, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }
    }
};
