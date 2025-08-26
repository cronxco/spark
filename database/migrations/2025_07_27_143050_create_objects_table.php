<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objects', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->timestampTz('time')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->uuid('integration_id');
            $table->text('concept');
            $table->text('type');
            $table->text('title');
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->text('url')->nullable();
            $table->text('media_url')->nullable();
            $table->vector('embeddings', 3)->nullable();
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('deleted_at')->nullable();
            $table->foreign('integration_id')->references('id')->on('integrations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objects');
    }
};
