<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_transaction_links', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // User ownership
            $table->uuid('user_id');

            // Source event
            $table->uuid('source_event_id');

            // Target event
            $table->uuid('target_event_id');

            // Proposed relationship type
            $table->string('relationship_type');

            // Confidence score (0-100)
            $table->decimal('confidence', 5, 2);

            // Detection strategy that found this match
            $table->string('detection_strategy');

            // Matching criteria used (e.g., which fields matched)
            $table->json('matching_criteria');

            // Status: pending, approved, rejected, auto_approved
            $table->string('status')->default('pending');

            // Value fields (for monetary relationships)
            $table->bigInteger('value')->nullable();
            $table->integer('value_multiplier')->nullable()->default(1);
            $table->string('value_unit')->nullable();

            // Additional metadata
            $table->json('metadata')->nullable();

            // If approved, reference to the created relationship
            $table->uuid('created_relationship_id')->nullable();

            // Timestamps
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('reviewed_at')->nullable();

            // Foreign keys
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('source_event_id')
                ->references('id')
                ->on('events')
                ->cascadeOnDelete();

            $table->foreign('target_event_id')
                ->references('id')
                ->on('events')
                ->cascadeOnDelete();

            $table->foreign('created_relationship_id')
                ->references('id')
                ->on('relationships')
                ->nullOnDelete();

            // Indexes
            $table->index('user_id');
            $table->index('status');
            $table->index('confidence');
            $table->index('detection_strategy');
            $table->index(['user_id', 'status']);

            // Unique constraint: prevent duplicate pending links
            $table->unique(
                ['user_id', 'source_event_id', 'target_event_id', 'relationship_type'],
                'pending_links_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_transaction_links');
    }
};
