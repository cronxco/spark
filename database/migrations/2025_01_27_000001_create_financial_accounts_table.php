<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->uuid('integration_id');
            $table->string('name');
            $table->string('account_type');
            $table->string('provider');
            $table->string('account_number')->nullable();
            $table->string('sort_code')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->decimal('interest_rate', 5, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('updated_at')->default(DB::raw("(now() AT TIME ZONE 'utc')"));
            $table->timestampTz('deleted_at')->nullable();
            
            // Indexes for performance
            $table->index('user_id');
            $table->index('integration_id');
            $table->index('account_type');
            $table->index('provider');
            
            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('integration_id')->references('id')->on('integrations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};