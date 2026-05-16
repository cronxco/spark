<?php

namespace App\Mcp\Tools;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-latest-flint-digest')]
#[IsIdempotent]
#[IsReadOnly]
class GetLatestFlintDigestTool extends Tool
{
    protected string $description = <<<'MARKDOWN'
        Retrieve Flint digest(s) for a given date, including all attached blocks.
        Defaults to today's most recent digest.

        Pass `all: true` to get every digest created on that date (e.g. morning + afternoon + agent-created).

        For flint_user_question blocks, the full metadata is returned including the user's
        answer, answer_note, and answered_at timestamp (null until the user responds).

        Use this to check whether the user has answered questions from a previously created digest.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $date = $request->get('date', 'today');
        $parsedDate = $date === 'today' ? Carbon::today() : Carbon::parse($date);
        $period = $request->get('period');
        $all = $request->boolean('all', false);

        $integrationIds = $user->integrations()->pluck('id');

        $query = Event::whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->whereDate('time', $parsedDate)
            ->with('blocks')
            ->orderBy('time', 'desc');

        if ($period) {
            $query->whereJsonContains('event_metadata->period', $period);
        }

        $events = $all ? $query->get() : $query->limit(1)->get();

        if ($events->isEmpty()) {
            $dateString = $parsedDate->toDateString();
            $suffix = $period ? " for period '{$period}'" : '';

            return Response::error("No Flint digest found for {$dateString}{$suffix}.");
        }

        $formatted = $events->map(fn ($event) => $this->formatDigest($event, $parsedDate));

        if ($all) {
            return Response::text(json_encode([
                'date' => $parsedDate->toDateString(),
                'count' => $formatted->count(),
                'digests' => $formatted->values(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return Response::text(json_encode(
            $formatted->first(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description('ISO date to retrieve the digest for (e.g. "2026-05-10"). Defaults to today.')
                ->default('today'),

            'period' => $schema->string()
                ->description('Filter by period: morning, afternoon, or evening. Returns the latest if omitted.'),

            'all' => $schema->boolean()
                ->description('Return all digests for the date instead of just the most recent. Useful when multiple digests were created in a day.')
                ->default(false),
        ];
    }

    private function formatDigest(Event $event, Carbon $date): array
    {
        $eventMeta = $event->event_metadata ?? [];

        $blocks = $event->blocks->map(function ($block) {
            $base = [
                'id' => $block->id,
                'block_type' => $block->block_type,
                'title' => $block->title,
                'time' => $block->time?->toIso8601String(),
            ];

            if ($block->block_type === 'flint_user_question') {
                $meta = $block->metadata ?? [];
                $base['question'] = $meta['question'] ?? null;
                $base['topic'] = $meta['topic'] ?? null;
                $base['priority'] = $meta['priority'] ?? null;
                $base['answer_options'] = $meta['answer_options'] ?? null;
                $base['answer'] = $meta['answer'] ?? null;
                $base['answer_note'] = $meta['answer_note'] ?? null;
                $base['answered_at'] = $meta['answered_at'] ?? null;
                $base['answered'] = ! is_null($meta['answer'] ?? null);
            } else {
                $base['content'] = $block->getContent();
            }

            return $base;
        });

        return [
            'event_id' => $event->id,
            'digest_object_id' => $eventMeta['digest_object_id'] ?? null,
            'date' => $date->toDateString(),
            'period' => $eventMeta['period'] ?? null,
            'title' => $eventMeta['title'] ?? $event->action,
            'summary' => $eventMeta['summary'] ?? null,
            'created_at' => $event->created_at->toIso8601String(),
            'block_count' => $blocks->count(),
            'blocks' => $blocks->values(),
        ];
    }
}
