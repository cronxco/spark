<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_executions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type', 32);
            $table->uuid('entity_id');
            $table->string('task_key');
            $table->string('task_name')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('triggered_by')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('queue')->nullable();
            $table->string('queue_connection')->nullable();
            $table->string('job_id')->nullable();
            $table->text('error')->nullable();
            $table->string('waiting_for')->nullable();
            $table->string('blocked_by')->nullable();
            $table->jsonb('changed_fields')->nullable();
            $table->jsonb('history')->nullable();
            $table->jsonb('last_success')->nullable();
            $table->timestampsTz();

            $table->unique(['entity_type', 'entity_id', 'task_key']);
            $table->index(['user_id', 'status', 'updated_at']);
            $table->index(['user_id', 'task_key', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_executions');
    }
};
