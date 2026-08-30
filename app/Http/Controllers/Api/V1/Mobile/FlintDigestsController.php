<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Event;
use App\Services\FlintDigestService;
use App\Support\EntityReferenceResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlintDigestsController extends Controller
{
    public function __construct(private FlintDigestService $digests) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->digests->create($request->user(), $request->all()), 201);
    }

    /**
     * GET /api/v1/mobile/flint/digests?date=YYYY-MM-DD&period=morning&all=true
     *
     * Returns Flint digest(s) for the given date. Defaults to today's most recent.
     * Pass all=true to get every digest created on that date.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'period' => ['nullable', 'string', 'in:morning,afternoon,evening'],
        ]);

        $date = isset($validated['date']) ? Carbon::parse($validated['date']) : Carbon::today();
        $all = $request->boolean('all');

        $integrationIds = $request->user()->integrations()->pluck('id');

        $query = Event::whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->whereDate('time', $date)
            ->with('blocks')
            ->orderBy('time', 'desc');

        if (isset($validated['period'])) {
            $query->whereJsonContains('event_metadata->period', $validated['period']);
        }

        $events = $all ? $query->get() : $query->limit(1)->get();

        if ($events->isEmpty()) {
            $suffix = isset($validated['period']) ? " for period '{$validated['period']}'" : '';

            return response()->json([
                'error' => "No Flint digest found for {$date->toDateString()}{$suffix}.",
            ], 404);
        }

        $formatted = $events->map(fn (Event $event) => $this->formatDigest($event, $date));

        if ($all) {
            return response()->json([
                'date' => $date->toDateString(),
                'count' => $formatted->count(),
                'digests' => $formatted->values(),
            ]);
        }

        return response()->json($formatted->first());
    }

    /**
     * GET /api/v1/mobile/flint/digests/{id}
     *
     * Returns a single Flint digest event with all blocks.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $integrationIds = $request->user()->integrations()->pluck('id');

        $event = Event::whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->with('blocks')
            ->find($id);

        if (! $event) {
            return response()->json(['error' => 'Digest not found.'], 404);
        }

        return response()->json($this->formatDigest($event, Carbon::parse($event->time)));
    }

    /**
     * POST /api/v1/mobile/flint/questions/{block}/answer
     *
     * Submit the user's answer to a flint_user_question block.
     */
    public function answer(Request $request, Block $block): JsonResponse
    {
        $block->loadMissing('event.integration');

        if ($block->event?->integration?->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        if ($block->block_type !== 'flint_user_question') {
            return response()->json(['error' => 'This block is not a user question.'], 422);
        }

        $validated = $request->validate([
            'answer' => ['required', 'string', 'max:1000'],
            'answer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $answeredAt = now()->toIso8601String();

        $block->metadata = array_merge($block->metadata ?? [], [
            'answer' => $validated['answer'],
            'answer_note' => $validated['answer_note'] ?? null,
            'answered_at' => $answeredAt,
        ]);

        $block->save();

        return response()->json([
            'block_id' => $block->id,
            'answer' => $validated['answer'],
            'answer_note' => $validated['answer_note'] ?? null,
            'answered_at' => $answeredAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDigest(Event $event, Carbon $date): array
    {
        $eventMeta = $event->event_metadata ?? [];

        // Batch-resolve every referenced event across all blocks in one query,
        // then hand each block its own ordered slice — avoids N+1.
        $allReferencedIds = $event->blocks
            ->flatMap(fn (Block $block) => $block->metadata['referenced_event_ids'] ?? [])
            ->unique()
            ->values()
            ->all();

        $referenceLookup = collect(
            EntityReferenceResolver::resolveEvents($allReferencedIds)
        )->keyBy('id');

        $blocks = $event->blocks->map(function (Block $block) use ($referenceLookup): array {
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
                $references = collect($block->metadata['referenced_event_ids'] ?? [])
                    ->map(fn ($id) => $referenceLookup->get($id))
                    ->filter()
                    ->values()
                    ->all();

                $base['content'] = EntityReferenceResolver::linkify(
                    $block->getContent(),
                    $references,
                );

                if (! empty($references)) {
                    $base['references'] = $references;
                }
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
            'unanswered_question_count' => $blocks->filter(
                fn (array $b) => $b['block_type'] === 'flint_user_question' && ! $b['answered']
            )->count(),
            'blocks' => $blocks->values(),
        ];
    }
}
