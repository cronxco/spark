<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_statistics', function (Blueprint $table) {
            $table->integer('baseline_window_days')->nullable()->default(90)->after('last_calculated_at');
        });
    }

    public function down(): void
    {
        Schema::table('metric_statistics', function (Blueprint $table) {
            $table->dropColumn('baseline_window_days');
        });
    }
};
