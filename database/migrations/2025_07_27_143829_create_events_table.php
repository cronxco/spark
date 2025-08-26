<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('source_id');
            $table->timestampTz('time')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->uuid('integration_id');
            $table->uuid('actor_id');
            $table->json('actor_metadata')->nullable();
            $table->text('service');
            $table->text('domain');
            $table->text('action');
            $table->bigInteger('value')->nullable();
            $table->integer('value_multiplier')->nullable();
            $table->text('value_unit')->nullable();
            $table->json('event_metadata')->nullable();
            $table->uuid('target_id');
            $table->json('target_metadata')->nullable();
            $table->text('embeddings')->nullable();
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('deleted_at')->nullable();

            // Foreign keys
            $table->foreign('target_id')->references('id')->on(Schema::getConnection()->getTablePrefix() . 'objects');
            $table->foreign('actor_id')->references('id')->on(Schema::getConnection()->getTablePrefix() . 'objects');
            $table->foreign('integration_id')->references('id')->on(Schema::getConnection()->getTablePrefix() . 'integrations');

            // Ensure no duplicate events per integration
            $table->unique(['integration_id', 'source_id'], 'events_integration_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
