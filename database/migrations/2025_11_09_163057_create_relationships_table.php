<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationships', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // User ownership
            $table->uuid('user_id');

            // Polymorphic "from" entity
            $table->string('from_type');
            $table->uuid('from_id');

            // Polymorphic "to" entity
            $table->string('to_type');
            $table->uuid('to_id');

            // Relationship type (e.g., 'linked_to', 'transferred_to')
            $table->string('type');

            // Value fields (optional, for monetary/numeric relationships)
            $table->bigInteger('value')->nullable();
            $table->integer('value_multiplier')->nullable()->default(1);
            $table->string('value_unit')->nullable();

            // Metadata
            $table->json('metadata')->nullable();

            // Timestamps
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('deleted_at')->nullable();

            // Foreign key to users
            $table->foreign('user_id', Schema::getConnection()->getTablePrefix().'relationships_user_id_foreign')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            // Indexes for polymorphic relationships
            $table->index(['from_type', 'from_id'], Schema::getConnection()->getTablePrefix().'relationships_from_index');
            $table->index(['to_type', 'to_id'], Schema::getConnection()->getTablePrefix().'relationships_to_index');
            $table->index('type', Schema::getConnection()->getTablePrefix().'relationships_type_index');
            $table->index('user_id', Schema::getConnection()->getTablePrefix().'relationships_user_id_index');

            // Unique constraint: one relationship per user + from + to + type combination
            // This prevents duplicate relationships
            $table->unique(
                ['user_id', 'from_type', 'from_id', 'to_type', 'to_id', 'type'],
                Schema::getConnection()->getTablePrefix().'relationships_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationships');
    }
};
