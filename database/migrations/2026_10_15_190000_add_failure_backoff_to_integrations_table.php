<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a failing integration back off instead of being re-dispatched on the
 * next scheduler tick after every permanent failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['consecutive_failures', 'last_failed_at']);
        });
    }
};
