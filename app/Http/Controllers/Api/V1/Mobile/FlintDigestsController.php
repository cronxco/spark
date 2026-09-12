<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Event;
use App\Services\Flint\FlintQuestionAnswerer;
use App\Services\FlintDigestService;
use App\Support\FlintBlockPresenter;
use App\Support\FlintDigestKind;
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

        $timezone = $request->user()->getTimezone();
        $date = isset($validated['date'])
            ? Carbon::parse($validated['date'], $timezone)
            : Carbon::today($timezone);
        $all = $request->boolean('all');

        $integrationIds = $request->user()->integrations()->pluck('id');

        // `whereDate()` compares the stored UTC date, so a timezone-aware date
        // alone changes nothing — see UpToSpeedController::localDayRange(),
        // whose docblock warns about exactly this. A digest is filed at the
        // start of the user's local day, which for anyone east or west of UTC
        // is a different UTC calendar date.
        [$dayStart, $dayEnd] = $this->localDayRange($date, $timezone);

        $query = Event::whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->where('time', '>=', $dayStart)
            ->where('time', '<', $dayEnd)
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

        $formatted = $events->map(fn (Event $event) => $this->formatDigest($event, $date, $integrationIds));

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

        return response()->json($this->formatDigest($event, Carbon::parse($event->time), $integrationIds));
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

        return response()->json(
            app(FlintQuestionAnswerer::class)->record(
                $block,
                $validated['answer'],
                $validated['answer_note'] ?? null,
            )
        );
    }


    /**
     * The half-open UTC interval covering one local calendar day:
     * `[start of local day, start of the next local day)`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function localDayRange(Carbon $localDay, string $timezone): array
    {
        $start = $localDay->copy()->timezone($timezone)->startOfDay();

        return [
            $start->copy()->setTimezone('UTC'),
            $start->copy()->addDay()->setTimezone('UTC'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDigest(Event $event, Carbon $date, mixed $integrationIds = null): array
    {
        $eventMeta = $event->event_metadata ?? [];

        $blocks = collect(FlintBlockPresenter::collection(
            $event->blocks,
            linkify: true,
            integrationIds: $integrationIds,
        ));

        return [
            'event_id' => $event->id,
            'digest_object_id' => $eventMeta['digest_object_id'] ?? null,
            'date' => $date->toDateString(),
            'period' => $eventMeta['period'] ?? null,
            'kind' => FlintDigestKind::for($event, $eventMeta),
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
