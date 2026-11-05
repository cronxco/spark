<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_statistics', function (Blueprint $table) {
            $table->timestampTz('anomaly_high_suppressed_until')->nullable()->after('baseline_reset_suggested_at');
            $table->timestampTz('anomaly_low_suppressed_until')->nullable()->after('anomaly_high_suppressed_until');
        });
    }

    public function down(): void
    {
        Schema::table('metric_statistics', function (Blueprint $table) {
            $table->dropColumn(['anomaly_high_suppressed_until', 'anomaly_low_suppressed_until']);
        });
    }
};
