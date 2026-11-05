<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_progress', function (Blueprint $table) {
            $table->json('updates')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('action_progress', function (Blueprint $table) {
            $table->dropColumn('updates');
        });
    }
};
