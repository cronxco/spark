<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Block;
use App\Models\Event;
use App\Services\HomeAssistant\HomeAssistantAttributionService;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a "who was watching?" flint_user_question once Will answers it,
 * by reassigning or discarding the related Home Assistant watch event.
 *
 * Triggered by BlockObserver::updated() -> TaskPipeline whenever a
 * flint_user_question block's metadata changes (i.e. an answer is saved).
 * Guarded to only run for this integration's own questions, and only once,
 * via the task's shouldRun condition (see HomeAssistantPlugin::getTaskDefinitions()).
 */
class ResolveHomeAssistantAttributionTask extends BaseTaskJob
{
    protected function execute(): void
    {
        /** @var Block $block */
        $block = $this->model;
        $metadata = $block->metadata ?? [];

        $eventId = $metadata['related_event_id'] ?? null;
        $answer = $metadata['answer'] ?? null;

        if (! $eventId || ! $answer) {
            return;
        }

        $event = Event::find($eventId);

        if (! $event) {
            Log::warning('Home Assistant attribution: related event not found', [
                'block_id' => $block->id,
                'event_id' => $eventId,
            ]);

            $this->markResolved($block);

            return;
        }

        $normalizedAnswer = strtolower(trim((string) $answer));

        // "me" is matched by equality (not substring) first, since a
        // household member's own name can otherwise contain "me" as a
        // substring (e.g. "James", "Amelia") and would be misread as Will.
        $isMe = $normalizedAnswer === 'me' || str_starts_with($normalizedAnswer, 'me ') || str_starts_with($normalizedAnswer, 'me/');

        if (! $isMe && (str_contains($normalizedAnswer, 'neither') || str_contains($normalizedAnswer, 'false'))) {
            $event->update([
                'event_metadata' => array_merge($event->event_metadata ?? [], [
                    'discarded' => true,
                    'attribution_method' => 'user_confirmed',
                ]),
            ]);
        } elseif (! $isMe) {
            // Anything else (typically the household member's name)
            // reassigns the event's actor.
            $integration = $event->integration;

            if (! $integration) {
                Log::warning('Home Assistant attribution: integration missing for event', [
                    'block_id' => $block->id,
                    'event_id' => $event->id,
                ]);

                $this->markResolved($block);

                return;
            }

            app(HomeAssistantAttributionService::class)->reassignToHouseholdMember(
                $event,
                $integration,
                'user_confirmed'
            );
        } else {
            $event->update([
                'event_metadata' => array_merge($event->event_metadata ?? [], [
                    'attributed_to' => 'will',
                    'attribution_method' => 'user_confirmed',
                ]),
            ]);
        }

        $this->markResolved($block);
    }

    private function markResolved(Block $block): void
    {
        $block->update([
            'metadata' => array_merge($block->metadata ?? [], [
                'attribution_resolved_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
