<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

/** Creates the same Flint digest payload for REST and MCP callers. */
class FlintDigestService
{
    /** @return array<string, mixed> */
    public function create(User $user, array $input): array
    {
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'period' => ['nullable', 'in:morning,afternoon,evening'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'blocks' => ['nullable', 'array', 'max:50'],
            'blocks.*.block_type' => ['required', 'string', 'starts_with:flint_', 'max:100'],
            'blocks.*.title' => ['required', 'string', 'max:255'],
            'blocks.*.content' => ['nullable', 'string', 'max:20000'],
            'blocks.*.referenced_event_ids' => ['nullable', 'array', 'max:100'],
            'blocks.*.referenced_event_ids.*' => ['uuid'],
            'blocks.*.question' => ['nullable', 'string', 'max:1000'],
            'blocks.*.topic' => ['nullable', 'string', 'max:100'],
            'blocks.*.priority' => ['nullable', 'in:low,medium,high'],
            'blocks.*.answer_options' => ['nullable', 'array', 'max:20'],
            'blocks.*.answer_options.*' => ['string', 'max:255'],
        ])->validate();

        $date = Carbon::parse($data['date'] ?? now()->toDateString())->startOfDay();
        $period = $data['period'] ?? $this->inferPeriod();
        $integration = $this->resolveIntegration($user);
        $digest = $this->resolveDigestObject($user, $period, $date);
        $actor = EventObject::firstOrCreate(
            ['user_id' => $user->id, 'concept' => 'user', 'type' => 'user_profile', 'title' => $user->name],
            ['time' => now()],
        );
        $blocks = $data['blocks'] ?? [];
        $event = Event::create([
            'source_id' => $digest->id, 'integration_id' => $integration->id, 'actor_id' => $actor->id,
            'service' => 'flint', 'domain' => 'knowledge', 'action' => 'had_summary', 'time' => $date,
            'value' => count($blocks), 'target_id' => $digest->id,
            'event_metadata' => ['period' => $period, 'digest_object_id' => $digest->id, 'title' => $data['title'], 'summary' => $data['summary'] ?? null],
        ]);
        Relationship::createRelationship(['user_id' => $user->id, 'from_type' => Event::class, 'from_id' => $event->id, 'to_type' => EventObject::class, 'to_id' => $digest->id, 'type' => 'part_of']);

        $ids = [];
        foreach ($blocks as $block) {
            $metadata = $block['block_type'] === 'flint_user_question'
                ? ['question' => $block['question'] ?? $block['title'], 'topic' => $block['topic'] ?? null, 'priority' => $block['priority'] ?? 'medium', 'answer_options' => $block['answer_options'] ?? null, 'answer' => null, 'answer_note' => null, 'answered_at' => null]
                : ['content' => $block['content'] ?? '', 'referenced_event_ids' => $block['referenced_event_ids'] ?? []];
            $ids[] = $event->createBlock(['block_type' => $block['block_type'], 'title' => $block['title'], 'time' => $date, 'metadata' => $metadata])->id;
        }

        return ['event_id' => $event->id, 'digest_object_id' => $digest->id, 'date' => $date->toDateString(), 'period' => $period, 'title' => $data['title'], 'block_count' => count($ids), 'block_ids' => $ids];
    }

    /**
     * Resolves (or creates) the user's Flint digest Integration.
     *
     * Shared between digest creation and the routine trigger jobs so both sides
     * resolve to the exact same row via firstOrCreate — the trigger jobs anchor
     * their TaskExecution rows here, and an anchor that only exists once a
     * digest has landed would go dark on exactly the runs worth seeing.
     */
    public function resolveIntegration(User $user): Integration
    {
        return Integration::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'flint', 'instance_type' => 'digest'],
            ['name' => 'Flint Digest', 'active' => true],
        );
    }

    /**
     * Resolves (or creates) the digest EventObject for a given user/period/date.
     * Shared between digest creation and pre-dispatch anchoring so both sides
     * resolve to the exact same row via firstOrCreate.
     */
    public function resolveDigestObject(User $user, string $period, Carbon $date): EventObject
    {
        return EventObject::firstOrCreate(
            ['user_id' => $user->id, 'concept' => 'digest', 'type' => $period . '_digest', 'title' => $date->format('Y-m-d') . ' ' . match ($period) {
                'morning' => 'AM', 'afternoon' => 'PM', default => 'EVE'
            }],
            ['time' => now(), 'metadata' => ['service' => 'flint', 'period' => $period, 'generated_at' => now()->toIso8601String()]],
        );
    }

    private function inferPeriod(): string
    {
        return match (true) {
            now()->hour <= 11 && now()->hour >= 5 => 'morning', now()->hour <= 16 => 'afternoon', default => 'evening'
        };
    }
}
