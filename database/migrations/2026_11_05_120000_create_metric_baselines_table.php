<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_baselines', function (Blueprint $table) {
            $prefix = Schema::getConnection()->getTablePrefix();

            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('metric_statistic_id');
            // Reserved for per-subject baselines (one per bank account, say).
            // Null means the metric as a whole.
            $table->uuid('subject_id')->nullable();
            $table->unsignedSmallInteger('window_days');
            // all, weekday, weekend, or dow_1..dow_7 (ISO weekday)
            $table->text('day_type')->default('all');
            $table->text('method');
            $table->decimal('centre', 20, 6);
            $table->decimal('spread', 20, 6);
            $table->decimal('lower', 20, 6);
            $table->decimal('upper', 20, 6);
            $table->unsignedInteger('sample_count');
            // p-value of the day-type test that justified this row. Null for 'all'.
            $table->double('effect_significance')->nullable();
            $table->unsignedSmallInteger('algorithm_version')->default(1);
            $table->timestampTz('computed_at');
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));

            $table->foreign('metric_statistic_id', $prefix . 'metric_baselines_statistic_foreign')
                ->references('id')->on('metric_statistics')->onDelete('cascade');

            $table->unique(
                ['metric_statistic_id', 'subject_id', 'window_days', 'day_type'],
                $prefix . 'metric_baselines_unique'
            );
        });

        // Postgres treats nulls as distinct in a unique index, so the
        // whole-metric rows need their own.
        DB::statement(
            'CREATE UNIQUE INDEX ' . Schema::getConnection()->getTablePrefix() . 'metric_baselines_whole_unique '
            . 'ON ' . Schema::getConnection()->getTablePrefix() . 'metric_baselines (metric_statistic_id, window_days, day_type) '
            . 'WHERE subject_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_baselines');
    }
};
