<?php

namespace App\Mcp\Tools;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class CreateFlintDigestTool extends Tool
{
    protected string $description = <<<'MARKDOWN'
        Create a Flint digest event with attached blocks.
        Use this to record an AI-generated digest, including user questions (flint_user_question)
        and editorial notes (flint_editorial_note) alongside standard content blocks.

        Block types:
        - `flint_user_question`: A question for the user. Provide `question`, optional `topic`,
          `priority` (low/medium/high), and optional `answer_options` array.
        - `flint_editorial_note`: Freeform AI commentary. Provide `content` (markdown).
        - Any `flint_*` type: Provide `content` (markdown) for the block body.

        Returns the created event ID and block IDs for future reference.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $title = $request->get('title');
        if (! $title) {
            return Response::error('title is required.');
        }

        $date = $request->get('date', 'today');
        $parsedDate = $date === 'today' ? Carbon::today() : Carbon::parse($date);

        $period = $request->get('period') ?? $this->inferPeriod();
        $summary = $request->get('summary');
        $blocksInput = $request->get('blocks') ?? [];

        // Get or create the Flint integration
        $integration = Integration::firstOrCreate(
            [
                'user_id' => $user->id,
                'service' => 'flint',
                'instance_type' => 'digest',
            ],
            [
                'name' => 'Flint Digest',
                'active' => true,
            ]
        );

        // Get or create the digest EventObject
        $digestTitle = $parsedDate->format('Y-m-d') . ' ' . match ($period) {
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

        // Get or create the user actor EventObject
        $actorObject = EventObject::firstOrCreate(
            [
                'user_id' => $user->id,
                'concept' => 'user',
                'type' => 'user_profile',
                'title' => $user->name,
            ],
            [
                'time' => now(),
            ]
        );

        // Create the summary event
        $event = Event::create([
            'source_id' => $digestObject->id,
            'integration_id' => $integration->id,
            'actor_id' => $actorObject->id,
            'service' => 'flint',
            'domain' => 'knowledge',
            'action' => 'had_summary',
            'time' => $parsedDate,
            'value' => count($blocksInput),
            'target_id' => $digestObject->id,
            'event_metadata' => [
                'period' => $period,
                'digest_object_id' => $digestObject->id,
                'title' => $title,
                'summary' => $summary,
            ],
        ]);

        // Relate event to digest object
        Relationship::createRelationship([
            'user_id' => $user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $digestObject->id,
            'type' => 'part_of',
        ]);

        // Create blocks
        $blockIds = [];
        foreach ($blocksInput as $blockData) {
            $blockType = $blockData['block_type'] ?? null;
            $blockTitle = $blockData['title'] ?? null;

            if (! $blockType || ! $blockTitle) {
                continue;
            }

            $metadata = $this->buildBlockMetadata($blockType, $blockData);

            $block = $event->createBlock([
                'block_type' => $blockType,
                'title' => $blockTitle,
                'time' => $parsedDate,
                'metadata' => $metadata,
            ]);

            $blockIds[] = $block->id;
        }

        return Response::text(json_encode([
            'event_id' => $event->id,
            'digest_object_id' => $digestObject->id,
            'date' => $parsedDate->toDateString(),
            'period' => $period,
            'title' => $title,
            'block_count' => count($blockIds),
            'block_ids' => $blockIds,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Title for the digest (e.g. "Morning Digest — May 10").')
                ->required(),

            'period' => $schema->string()
                ->description('Time period: morning, afternoon, or evening. Inferred from current time if omitted.'),

            'date' => $schema->string()
                ->description('ISO date for the digest (e.g. "2026-05-10"). Defaults to today.')
                ->default('today'),

            'summary' => $schema->string()
                ->description('Optional headline summary content for the digest.'),

            'blocks' => $schema->array()
                ->items($schema->object([
                    'block_type' => $schema->string()
                        ->required()
                        ->description('Block type (e.g. flint_user_question, flint_editorial_note, flint_insight).'),
                    'title' => $schema->string()
                        ->required()
                        ->description('Block title.'),
                    'content' => $schema->string()
                        ->description('Markdown content — for flint_editorial_note and other content blocks.'),
                    'question' => $schema->string()
                        ->description('For flint_user_question: the question text to display to the user.'),
                    'topic' => $schema->string()
                        ->description('For flint_user_question: category (e.g. health, money, routine).'),
                    'priority' => $schema->string()
                        ->description('For flint_user_question: low, medium, or high.'),
                    'answer_options' => $schema->array()
                        ->items($schema->string())
                        ->description('For flint_user_question: optional multiple-choice answers. Omit for freeform.'),
                ]))
                ->description('Blocks to attach to this digest.'),
        ];
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

    /**
     * Build the metadata array for a block based on its type and input data.
     */
    private function buildBlockMetadata(string $blockType, array $blockData): array
    {
        if ($blockType === 'flint_user_question') {
            return [
                'question' => $blockData['question'] ?? $blockData['title'],
                'topic' => $blockData['topic'] ?? null,
                'priority' => $blockData['priority'] ?? 'medium',
                'answer_options' => $blockData['answer_options'] ?? null,
                'answer' => null,
                'answer_note' => null,
                'answered_at' => null,
            ];
        }

        return [
            'content' => $blockData['content'] ?? '',
        ];
    }
}
