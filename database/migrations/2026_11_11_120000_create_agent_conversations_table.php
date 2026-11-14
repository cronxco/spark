<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * Conversation storage for laravel/ai agents that remember their threads
 * (Ask Flint). The package's own migration, with `participant_id` widened to
 * a string because Spark users are keyed by UUID.
 */
return new class extends AiMigration
{
    public function up(): void
    {
        Schema::create(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('participant_type')->nullable();
            $table->string('participant_id', 36)->nullable();
            $table->string('title');
            $table->timestamps();

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::create(config('ai.conversations.tables.messages', 'agent_conversation_messages'), function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->string('participant_type')->nullable();
            $table->string('participant_id', 36)->nullable();
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->longText('steps');
            $table->text('usage');
            $table->text('meta');
            $table->string('status', 25);
            $table->timestamps();

            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('ai.conversations.tables.messages', 'agent_conversation_messages'));
        Schema::dropIfExists(config('ai.conversations.tables.conversations', 'agent_conversations'));
    }
};
