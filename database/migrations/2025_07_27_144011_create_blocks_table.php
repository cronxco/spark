<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('event_id')->nullable();
            $table->timestampTz('time')->nullable();
            $table->uuid('integration_id');
            $table->text('title')->nullable();
            $table->text('content')->nullable();
            $table->text('url')->nullable();
            $table->text('media_url')->nullable();
            $table->bigInteger('value')->nullable();
            $table->integer('value_multiplier')->nullable();
            $table->text('value_unit')->nullable();
            $table->text('embeddings')->nullable();
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('deleted_at')->nullable();
            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('integration_id')->references('id')->on('integrations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
