<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->string('group_key')->nullable();

            $table->index(
                ['notifiable_type', 'notifiable_id', 'archived_at', 'created_at', 'id'],
                'notifications_owner_archive_cursor_index'
            );
            $table->index(
                ['notifiable_type', 'notifiable_id', 'group_key', 'archived_at'],
                'notifications_owner_group_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_owner_archive_cursor_index');
            $table->dropIndex('notifications_owner_group_index');
            $table->dropColumn(['archived_at', 'group_key']);
        });
    }
};
