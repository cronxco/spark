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
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('objects', function (Blueprint $table) use ($isPgsql) {
            if ($isPgsql) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->vector('embeddings', 3)->nullable();
            } else {
                $table->uuid('id')->primary();
                $table->text('embeddings')->nullable();
            }
            $table->timestamp('time')->useCurrent();
            $table->uuid('integration_id');
            $table->text('concept');
            $table->text('type');
            $table->text('title');
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->text('url')->nullable();
            $table->text('media_url')->nullable();
            $table->timestamps();
            $table->foreign('integration_id')->references('id')->on('integrations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('objects');
    }
};
