<?php

namespace App\Jobs\Flint;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use App\Services\AssistantPromptingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateDailyDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public User $user,
        public string $period, // 'morning' or 'afternoon'
    ) {}

    public function handle(AssistantPromptingService $promptingService): void
    {
        Log::info('Generating Flint digest', [
            'user_id' => $this->user->id,
            'period' => $this->period,
        ]);

        try {
            // Step 1: Generate digest via OpenAI
            $digestData = $promptingService->generateDigest($this->user, $this->period);

            // Step 2: Get or create Flint integration
            $integration = $this->getFlintIntegration();

            // Step 3: Get or create user EventObject (actor)
            $userObject = $this->getUserEventObject();

            // Step 4: Create digest EventObject
            $digestObject = $this->createDigestEventObject();

            // Step 5: Create Event with had_summary action
            $event = $this->createSummaryEvent($digestObject, $integration, $userObject, $this->getBlockCount($digestData));

            // Step 4: Create all blocks
            $blocks = $this->createBlocks($digestData, $event);

            // Step 5: Create relationships
            $this->createRelationships($event, $digestObject, $blocks);

            // Step 6: Send notification
            $this->sendNotification($digestObject, $blocks);

            Log::info('Flint digest generated successfully', [
                'user_id' => $this->user->id,
                'period' => $this->period,
                'digest_object_id' => $digestObject->id,
                'event_id' => $event->id,
                'blocks_created' => count($blocks),
            ]);

        } catch (Exception $e) {
            Log::error('Failed to generate Flint digest', [
                'user_id' => $this->user->id,
                'period' => $this->period,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GenerateDailyDigestJob failed permanently', [
            'user_id' => $this->user->id,
            'period' => $this->period,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    private function getFlintIntegration(): Integration
    {
        return Integration::firstOrCreate(
            [
                'user_id' => $this->user->id,
                'service' => 'flint',
                'instance_type' => 'digest',
            ],
            [
                'name' => 'Flint Digest',
                'active' => true,
            ]
        );
    }

    private function getUserEventObject(): EventObject
    {
        return EventObject::firstOrCreate(
            [
                'user_id' => $this->user->id,
                'concept' => 'user',
                'type' => 'user_profile',
                'title' => $this->user->name,
            ],
            [
                'time' => now(),
                'metadata' => [
                    'email' => $this->user->email,
                ],
            ]
        );
    }

    private function createDigestEventObject(): EventObject
    {
        $title = Carbon::now()->format('Y-m-d') . ' ' .
                 ($this->period === 'morning' ? 'AM' : 'PM');

        return EventObject::firstOrCreate(
            [
                'user_id' => $this->user->id,
                'concept' => 'digest',
                'type' => $this->period . '_digest',
                'title' => $title,
            ],
            [
                'time' => now(),
                'metadata' => [
                    'service' => 'flint',
                    'period' => $this->period,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]
        );
    }

    private function createSummaryEvent(EventObject $digestObject, Integration $integration, EventObject $actor, int $blockCount): Event
    {
        $event = Event::create([
            'source_id' => $digestObject->id,
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'user_id' => $this->user->id,
            'service' => 'flint',
            'domain' => 'knowledge',
            'action' => 'had_summary',
            'time' => now(),
            'value' => $blockCount,
            'target_id' => $digestObject->id,
            'event_metadata' => [
                'period' => $this->period,
                'digest_object_id' => $digestObject->id,
                'model' => config('services.openai.models.gpt5_mini'),
            ],
        ]);

        // Create part_of relationship: Event is part of DigestObject
        Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $digestObject->id,
            'type' => 'part_of',
        ]);

        return $event;
    }

    private function getBlockCount(array $digestData): int
    {
        $count = 5; // headline, key_points, actions, insight, suggestion

        if ($digestData['things_to_be_aware_of'] !== null) {
            $count++;
        }

        return $count;
    }

    private function createBlocks(array $digestData, Event $event): array
    {
        $blocks = [];

        // 1. Headline block
        $blocks[] = Block::updateOrCreate(
            [
                'event_id' => $event->id,
                'block_type' => 'flint_summarised_headline',
                'title' => 'Daily Headline',
            ],
            [
                'user_id' => $this->user->id,
                'service' => 'flint',
                'time' => now(),
                'metadata' => [
                    'content' => $digestData['headline'],
                    'period' => $this->period,
                ],
            ]
        );

        // 2. Key points block
        $blocks[] = Block::updateOrCreate(
            [
                'event_id' => $event->id,
                'block_type' => 'flint_five_key_points',
                'title' => 'Key Points',
            ],
            [
                'user_id' => $this->user->id,
                'service' => 'flint',
                'value' => count($digestData['key_points']),
                'time' => now(),
                'metadata' => [
                    'content' => implode("\n", array_map(fn ($p, $i) => ($i + 1) . '. ' . $p, $digestData['key_points'], array_keys($digestData['key_points']))),
                    'points' => $digestData['key_points'],
                    'period' => $this->period,
                ],
            ]
        );

        // 3. Actions required block
        $blocks[] = Block::updateOrCreate(
            [
                'event_id' => $event->id,
                'block_type' => 'flint_actions_required',
                'title' => 'Actions Required',
            ],
            [
                'user_id' => $this->user->id,
                'service' => 'flint',
                'value' => count($digestData['actions_required']),
                'time' => now(),
                'metadata' => [
                    'content' => $this->formatActionsContent($digestData['actions_required']),
                    'actions' => $digestData['actions_required'],
                    'period' => $this->period,
                ],
            ]
        );

        // 4. Things to be aware of (only if not null)
        if ($digestData['things_to_be_aware_of'] !== null) {
            $blocks[] = Block::updateOrCreate(
                [
                    'event_id' => $event->id,
                    'block_type' => 'flint_things_to_be_aware_of',
                    'title' => 'Awareness Alerts',
                ],
                [
                    'user_id' => $this->user->id,
                    'service' => 'flint',
                    'value' => count($digestData['things_to_be_aware_of']),
                    'time' => now(),
                    'metadata' => [
                        'content' => $this->formatAlertsContent($digestData['things_to_be_aware_of']),
                        'alerts' => $digestData['things_to_be_aware_of'],
                        'period' => $this->period,
                    ],
                ]
            );
        }

        // 5. Insight block
        $blocks[] = Block::updateOrCreate(
            [
                'event_id' => $event->id,
                'block_type' => 'flint_insight',
                'title' => $digestData['insight']['title'],
            ],
            [
                'user_id' => $this->user->id,
                'service' => 'flint',
                'time' => now(),
                'metadata' => [
                    'content' => $digestData['insight']['content'],
                    'title' => $digestData['insight']['title'],
                    'supporting_data' => $digestData['insight']['supporting_data'],
                    'period' => $this->period,
                ],
            ]
        );

        // 6. Suggestion block
        $blocks[] = Block::updateOrCreate(
            [
                'event_id' => $event->id,
                'block_type' => 'flint_suggestion',
                'title' => $digestData['suggestion']['title'],
            ],
            [
                'user_id' => $this->user->id,
                'service' => 'flint',
                'time' => now(),
                'metadata' => [
                    'content' => $digestData['suggestion']['content'],
                    'title' => $digestData['suggestion']['title'],
                    'actionable' => $digestData['suggestion']['actionable'],
                    'automation_hint' => $digestData['suggestion']['automation_hint'],
                    'period' => $this->period,
                ],
            ]
        );

        return $blocks;
    }

    private function formatActionsContent(array $actions): string
    {
        if (empty($actions)) {
            return 'No actions required at this time.';
        }

        return implode("\n\n", array_map(function ($action, $index) {
            $priority = strtoupper($action['priority']);
            $dueDate = $action['suggested_due_date']
                ? " (Due: {$action['suggested_due_date']})"
                : '';

            return ($index + 1) . ". [{$priority}] {$action['title']}{$dueDate}\n   {$action['description']}";
        }, $actions, array_keys($actions)));
    }

    private function formatAlertsContent(array $alerts): string
    {
        return implode("\n\n", array_map(function ($alert, $index) {
            $severity = strtoupper($alert['severity']);
            $service = $alert['related_service']
                ? " ({$alert['related_service']})"
                : '';

            return ($index + 1) . ". [{$severity}] {$alert['title']}{$service}\n   {$alert['description']}";
        }, $alerts, array_keys($alerts)));
    }

    private function createRelationships(Event $event, EventObject $digestObject, array $blocks): void
    {
        foreach ($blocks as $block) {
            // Block is part of Event (check if relationship exists first)
            if (! Relationship::where([
                'user_id' => $this->user->id,
                'from_type' => Block::class,
                'from_id' => $block->id,
                'to_type' => Event::class,
                'to_id' => $event->id,
                'type' => 'part_of',
            ])->exists()) {
                Relationship::createRelationship([
                    'user_id' => $this->user->id,
                    'from_type' => Block::class,
                    'from_id' => $block->id,
                    'to_type' => Event::class,
                    'to_id' => $event->id,
                    'type' => 'part_of',
                ]);
            }

            // Block is part of DigestObject (check if relationship exists first)
            if (! Relationship::where([
                'user_id' => $this->user->id,
                'from_type' => Block::class,
                'from_id' => $block->id,
                'to_type' => EventObject::class,
                'to_id' => $digestObject->id,
                'type' => 'part_of',
            ])->exists()) {
                Relationship::createRelationship([
                    'user_id' => $this->user->id,
                    'from_type' => Block::class,
                    'from_id' => $block->id,
                    'to_type' => EventObject::class,
                    'to_id' => $digestObject->id,
                    'type' => 'part_of',
                ]);
            }
        }
    }

    private function sendNotification(EventObject $digestObject, array $blocks): void
    {
        try {
            $this->user->notify(new DailyDigestReady(
                $digestObject,
                $this->period,
                $blocks
            ));

            Log::info('Digest notification sent', [
                'user_id' => $this->user->id,
                'period' => $this->period,
            ]);
        } catch (Exception $e) {
            Log::warning('Failed to send digest notification', [
                'user_id' => $this->user->id,
                'period' => $this->period,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
