<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            if (Schema::hasColumn('integrations', 'update_frequency_minutes')) {
                $table->dropColumn('update_frequency_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->integer('update_frequency_minutes')->default(15);
        });
    }
};
