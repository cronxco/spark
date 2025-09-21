<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_progress', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('action_type'); // e.g., 'deletion', 'migration', 'sync', etc.
            $table->string('action_id'); // e.g., integration_group_id, migration_name, etc.
            $table->string('step');
            $table->text('message');
            $table->integer('progress')->default(0);
            $table->integer('total')->default(100);
            $table->json('details')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action_type', 'action_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action_type', 'action_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_progress');
    }
};
