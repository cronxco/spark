<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('device_type')->default('web')->after('content_encoding');
            $table->string('app_environment')->nullable()->after('device_type');
            $table->string('bundle_id')->nullable()->after('app_environment');
            $table->string('app_version')->nullable()->after('bundle_id');
            $table->string('os_version')->nullable()->after('app_version');

            $table->index(['device_type', 'app_environment'], 'push_subs_device_env_idx');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropIndex('push_subs_device_env_idx');
            $table->dropColumn([
                'device_type',
                'app_environment',
                'bundle_id',
                'app_version',
                'os_version',
            ]);
        });
    }
};
