<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_statistics', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->text('service');
            $table->text('action');
            $table->text('value_unit');
            $table->integer('event_count')->default(0);
            $table->timestampTz('first_event_at')->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->decimal('min_value', 20, 6)->nullable();
            $table->decimal('max_value', 20, 6)->nullable();
            $table->decimal('mean_value', 20, 6)->nullable();
            $table->decimal('stddev_value', 20, 6)->nullable();
            $table->decimal('normal_lower_bound', 20, 6)->nullable();
            $table->decimal('normal_upper_bound', 20, 6)->nullable();
            $table->timestampTz('last_calculated_at')->nullable();
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));

            // Foreign key
            $table->foreign('user_id', Schema::getConnection()->getTablePrefix() . 'metric_statistics_user_id_foreign')
                ->references('id')->on('users')->onDelete('cascade');

            // Unique constraint: one metric per user/service/action/unit combination
            $table->unique(['user_id', 'service', 'action', 'value_unit'],
                Schema::getConnection()->getTablePrefix() . 'metric_statistics_unique');

            // Index for batch processing
            $table->index('last_calculated_at', Schema::getConnection()->getTablePrefix() . 'metric_statistics_last_calc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_statistics');
    }
};
