<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('query');
            $table->enum('type', ['semantic', 'keyword', 'hybrid'])->default('semantic');
            $table->enum('source', ['api', 'spotlight_auto', 'spotlight_mode'])->default('api');
            $table->integer('results_count')->default(0);
            $table->integer('events_count')->default(0);
            $table->integer('blocks_count')->default(0);
            $table->float('avg_similarity')->nullable();
            $table->float('top_similarity')->nullable();
            $table->float('threshold')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->json('filters')->nullable();
            $table->boolean('clicked')->default(false);
            $table->uuid('clicked_result_id')->nullable();
            $table->string('clicked_result_type')->nullable(); // 'event' or 'block'
            $table->timestamps();

            // Indexes for performance
            $table->index('user_id');
            $table->index('type');
            $table->index('source');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
