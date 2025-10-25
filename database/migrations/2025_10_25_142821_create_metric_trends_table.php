<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_trends', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('metric_statistic_id');
            $table->text('type'); // anomaly_high, anomaly_low, trend_up_weekly, etc.
            $table->timestampTz('detected_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('baseline_value', 20, 6)->nullable();
            $table->decimal('current_value', 20, 6)->nullable();
            $table->decimal('deviation', 20, 6)->nullable();
            $table->decimal('significance_score', 10, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));

            // Foreign key
            $table->foreign('metric_statistic_id', Schema::getConnection()->getTablePrefix() . 'metric_trends_stat_id_foreign')
                ->references('id')->on('metric_statistics')->onDelete('cascade');

            // Index for querying unacknowledged trends by metric and type
            $table->index(['metric_statistic_id', 'type', 'acknowledged_at'],
                Schema::getConnection()->getTablePrefix() . 'metric_trends_query_idx');

            // Index for recent trends
            $table->index('detected_at', Schema::getConnection()->getTablePrefix() . 'metric_trends_detected_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_trends');
    }
};
