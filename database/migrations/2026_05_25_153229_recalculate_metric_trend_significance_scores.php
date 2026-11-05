<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('metric_trends')
            ->whereIn('type', ['anomaly_high', 'anomaly_low'])
            ->whereNotNull('deviation')
            ->update([
                'significance_score' => DB::raw('tanh(deviation::double precision / 2)'),
            ]);
    }

    public function down(): void
    {
        DB::table('metric_trends')
            ->whereIn('type', ['anomaly_high', 'anomaly_low'])
            ->whereNotNull('deviation')
            ->update([
                'significance_score' => DB::raw('LEAST(deviation::double precision / 2, 1.0)'),
            ]);
    }
};
