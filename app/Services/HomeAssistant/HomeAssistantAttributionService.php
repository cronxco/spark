<?php

namespace App\Services\HomeAssistant;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Person;
use Carbon\Carbon;

/**
 * Decides who a Home Assistant "watched" event should be attributed to,
 * since the media player is shared with another household member.
 *
 * - If HA's presence data clearly points to one person, attribute silently.
 * - Otherwise, ask via the existing flint_user_question mechanism and let
 *   ResolveHomeAssistantAttributionTask (a TaskPipeline task registered by
 *   HomeAssistantPlugin) resolve the answer once it comes in.
 */
class HomeAssistantAttributionService
{
    public function attribute(Event $event, Integration $integration): void
    {
        $metadata = $event->event_metadata ?? [];
        $willHome = $metadata['will_home'] ?? null;
        $danHome = $metadata['dan_home'] ?? null;

        // Only Will home: leave attributed to Will (the default actor), no action needed.
        if ($willHome === true && $danHome !== true) {
            return;
        }

        // Only the other household member home: reassign silently.
        if ($danHome === true && $willHome !== true) {
            $this->reassignToHouseholdMember($event, $integration, 'presence');

            return;
        }

        // Both home, both away, or presence unknown: genuinely ambiguous.
        $this->askWhoWasWatching($event, $integration);
    }

    public function reassignToHouseholdMember(Event $event, Integration $integration, string $method = 'presence'): void
    {
        $name = $integration->configuration['household_member_name'] ?? 'Dan';

        $person = Person::where('user_id', $integration->user_id)
            ->where('title', $name)
            ->first();

        if (! $person) {
            $person = Person::create([
                'user_id' => $integration->user_id,
                'type' => 'person',
                'title' => $name,
                'time' => now(),
                'metadata' => [],
            ]);
        }

        $event->update([
            'actor_id' => $person->id,
            'event_metadata' => array_merge($event->event_metadata ?? [], [
                'attributed_to' => strtolower($name),
                'attribution_method' => $method,
            ]),
        ]);
    }

    public function askWhoWasWatching(Event $event, Integration $integration): void
    {
        $user = $integration->user;

        if (! $user) {
            return;
        }

        $mediaTitle = $event->target?->title ?? 'this';
        $householdMemberName = $integration->configuration['household_member_name'] ?? 'Dan';

        $period = $this->inferPeriod();
        $today = Carbon::today();

        $flintIntegration = Integration::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'flint', 'instance_type' => 'digest'],
            ['name' => 'Flint Digest', 'active' => true]
        );

        $digestTitle = $today->format('Y-m-d') . ' ' . match ($period) {
            'morning' => 'AM',
            'afternoon' => 'PM',
            default => 'EVE',
        };

        $digestObject = EventObject::firstOrCreate(
            [
                'user_id' => $user->id,
                'concept' => 'digest',
                'type' => $period . '_digest',
                'title' => $digestTitle,
            ],
            [
                'time' => now(),
                'metadata' => [
                    'service' => 'flint',
                    'period' => $period,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]
        );

        $actorObject = EventObject::firstOrCreate(
            [
                'user_id' => $user->id,
                'concept' => 'user',
                'type' => 'user_profile',
                'title' => $user->name,
            ],
            ['time' => now()]
        );

        // Reuse today's digest event for this period if one already exists,
        // rather than creating a new near-duplicate digest for every question.
        $digestEvent = Event::firstOrCreate(
            [
                'integration_id' => $flintIntegration->id,
                'source_id' => $digestObject->id,
            ],
            [
                'actor_id' => $actorObject->id,
                'service' => 'flint',
                'domain' => 'knowledge',
                'action' => 'had_summary',
                'time' => $today,
                'value' => 0,
                'target_id' => $digestObject->id,
                'event_metadata' => [
                    'period' => $period,
                    'digest_object_id' => $digestObject->id,
                    'title' => $digestTitle,
                ],
            ]
        );

        $digestEvent->createBlock([
            'block_type' => 'flint_user_question',
            'title' => 'Who was watching?',
            'time' => now(),
            'metadata' => [
                'question' => "Was this you watching \"{$mediaTitle}\", or was it {$householdMemberName}?",
                'topic' => 'media',
                'priority' => 'low',
                'answer_options' => ['Me', $householdMemberName, 'Neither / false trigger'],
                'answer' => null,
                'answer_note' => null,
                'answered_at' => null,
                'related_event_id' => $event->id,
                'related_service' => 'home_assistant',
            ],
        ]);
    }

    private function inferPeriod(): string
    {
        $hour = (int) now()->format('G');

        if ($hour >= 5 && $hour <= 11) {
            return 'morning';
        }

        if ($hour >= 12 && $hour <= 16) {
            return 'afternoon';
        }

        return 'evening';
    }
}
