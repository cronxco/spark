<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_statistics', function (Blueprint $table) {
            $table->timestampTz('baseline_reset_suggested_at')->nullable()->after('baseline_window_days');
        });

        // Backfill: set flag on any existing metric_statistic that already has
        // unacknowledged monthly or quarterly trend records.
        $prefix = DB::getTablePrefix();
        $ms = $prefix . 'metric_statistics';
        $mt = $prefix . 'metric_trends';

        DB::statement(<<<SQL
            UPDATE {$ms} ms
            SET baseline_reset_suggested_at = (
                SELECT MIN(mt.detected_at)
                FROM {$mt} mt
                WHERE mt.metric_statistic_id = ms.id
                  AND mt.type IN (
                      'trend_up_monthly', 'trend_down_monthly',
                      'trend_up_quarterly', 'trend_down_quarterly'
                  )
                  AND mt.acknowledged_at IS NULL
            )
            WHERE EXISTS (
                SELECT 1 FROM {$mt} mt
                WHERE mt.metric_statistic_id = ms.id
                  AND mt.type IN (
                      'trend_up_monthly', 'trend_down_monthly',
                      'trend_up_quarterly', 'trend_down_quarterly'
                  )
                  AND mt.acknowledged_at IS NULL
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('metric_statistics', function (Blueprint $table) {
            $table->dropColumn('baseline_reset_suggested_at');
        });
    }
};
