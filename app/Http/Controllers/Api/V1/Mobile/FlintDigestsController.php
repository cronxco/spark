<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Event;
use App\Services\Api\ResourceVersion;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\FlintQuestionActionService;
use App\Services\FlintDigestService;
use App\Support\FlintAudience;
use App\Support\FlintBlockPresenter;
use App\Support\FlintDigestFreshness;
use App\Support\FlintDigestKind;
use App\Support\FlintDigestOpener;
use App\Support\FlintQuestion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string'],
        ]);

        if (isset($validated['from'], $validated['to'])) {
            return $this->history($request, $validated);
        }

        // MR-16: resolved the same way every day-scoped endpoint does — the
        // user's effective (acknowledged time-travel) timezone, not just the
        // profile default.
        $timezone = app(EffectiveTimezoneResolver::class)->timezoneFor($request->user());
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

        $formatted = $events->map(fn (Event $event) => $this->formatDigest($event, $date, $integrationIds, $timezone));

        if ($all) {
            return response()->json([
                'date' => $date->toDateString(),
                'effective_timezone' => $timezone,
                'count' => $formatted->count(),
                'digests' => $formatted->values(),
            ]);
        }

        return response()->json($formatted->first());
    }

    /**
     * GET /api/v1/mobile/flint/digests/latest?kind=briefing
     *
     * MR-8: the single most recent digest across dates, so a client on cold
     * start doesn't have to ask for today, inspect the result, then ask again
     * for yesterday — the digest that answers "what has Flint most recently
     * written" isn't expressible as a `date` query alone before the day's
     * first digest has run.
     */
    public function latest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'string', Rule::in(FlintDigestKind::ALL)],
        ]);

        $timezone = app(EffectiveTimezoneResolver::class)->timezoneFor($request->user());
        $integrationIds = $request->user()->integrations()->pluck('id');

        $query = Event::whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->with('blocks')
            ->orderByDesc('time')
            ->orderByDesc('id');

        $events = $query->limit(25)->get();

        if (isset($validated['kind'])) {
            $events = $events->filter(
                fn (Event $event) => FlintDigestKind::for($event, $event->event_metadata ?? []) === $validated['kind']
            );
        }

        $event = $events->first();

        if (! $event) {
            $suffix = isset($validated['kind']) ? " of kind '{$validated['kind']}'" : '';

            return response()->json(['error' => "No Flint digest found{$suffix}."], 404);
        }

        return response()->json(
            $this->formatDigest($event, Carbon::parse($event->time, $timezone), $integrationIds, $timezone)
        );
    }

    /**
     * GET /api/v1/mobile/flint/digests/{id}
     *
     * Returns a single Flint digest event with all blocks.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $timezone = app(EffectiveTimezoneResolver::class)->timezoneFor($request->user());
        $integrationIds = $request->user()->integrations()->pluck('id');

        $event = Event::whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->with('blocks')
            ->find($id);

        if (! $event) {
            return response()->json(['error' => 'Digest not found.'], 404);
        }

        return response()->json($this->formatDigest($event, Carbon::parse($event->time), $integrationIds, $timezone));
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

        $result = app(FlintQuestionActionService::class)->recordLegacy(
            $request->user(),
            (string) $block->id,
            $validated['answer'],
            $validated['answer_note'] ?? null,
        );

        if ($result['status'] >= 400) {
            return response()->json(['error' => $result['message'] ?? 'Forbidden.'], $result['status']);
        }

        $answer = $result['data']['effective_answer'];

        return response()->json([
            'block_id' => $block->id,
            'answer' => $answer['answer'],
            'answer_note' => $answer['context'],
            'answered_at' => $answer['answered_at'],
            // MR-11: the full updated question resource, additively — the
            // legacy flat fields above stay put for clients still reading
            // them, but a client can now update in place from `data` without
            // a follow-up GET, same as the current POST .../actions endpoint.
            'data' => $result['data'],
        ])->header('Deprecation', 'true')->header('Sunset', now()->addMonths(3)->toRfc7231String());
    }

    /** @param array<string, mixed> $validated */
    private function history(Request $request, array $validated): JsonResponse
    {
        $timezones = app(EffectiveTimezoneResolver::class);
        $timezone = $timezones->timezoneFor($request->user());
        $from = Carbon::parse($validated['from'], $timezone)->startOfDay();
        $to = Carbon::parse($validated['to'], $timezone)->startOfDay();
        $today = $timezones->today($request->user())->startOfDay();

        if ($from->gt($to)) {
            return response()->json(['message' => 'The from date must be on or before the to date.'], 422);
        }
        if ($from->diffInDays($to) + 1 > 30) {
            return response()->json(['message' => 'Digest history is limited to 30 local calendar days.'], 422);
        }
        if ($from->gt($today)) {
            return response()->json(['message' => 'A future-only digest range is not valid.'], 422);
        }

        $integrationIds = $request->user()->integrations()->pluck('id');
        $paginator = Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->where('time', '>=', $from->copy()->utc())
            ->where('time', '<', $to->copy()->addDay()->utc())
            ->withCount(['blocks as unanswered_question_count' => fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('block_type', FlintQuestion::BLOCK_TYPE)
                ->whereNull('metadata->answer')
                ->whereNull('metadata->retired_at')
                ->whereNull('metadata->skipped_at')])
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['limit'] ?? 20), ['*'], 'cursor');
        $versions = app(ResourceVersion::class);

        return response()->json([
            'data' => collect($paginator->items())->map(function (Event $event) use ($timezone, $versions): array {
                $metadata = $event->event_metadata ?? [];
                $generatedAt = Carbon::parse($metadata['generated_at'] ?? $event->created_at);

                return [
                    'id' => (string) $event->id,
                    'local_date' => $metadata['local_date'] ?? $event->time->copy()->setTimezone($timezone)->toDateString(),
                    'period' => $metadata['period'] ?? null,
                    'kind' => FlintDigestKind::for($event, $metadata),
                    'title' => $metadata['title'] ?? $event->action,
                    'summary' => $metadata['summary'] ?? null,
                    'generated_at' => $generatedAt->setTimezone($timezone)->toIso8601String(),
                    'updated_at' => $event->updated_at?->setTimezone($timezone)->toIso8601String(),
                    'unanswered_question_count' => (int) $event->unanswered_question_count,
                    'version' => 'W/' . $versions->etag($event),
                    'freshness' => FlintDigestFreshness::for($generatedAt),
                ];
            })->all(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'effective_timezone' => $timezone,
                'account_id' => (string) $request->user()->id,
            ],
        ]);
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
    private function formatDigest(Event $event, Carbon $date, mixed $integrationIds = null, ?string $timezone = null): array
    {
        $eventMeta = $event->event_metadata ?? [];

        $blocks = collect(FlintBlockPresenter::collection(
            $event->blocks,
            linkify: true,
            integrationIds: $integrationIds,
            audience: FlintAudience::MobileReader,
        ));

        return [
            'event_id' => $event->id,
            'digest_object_id' => $eventMeta['digest_object_id'] ?? null,
            'date' => $date->toDateString(),
            'effective_timezone' => $timezone,
            'period' => $eventMeta['period'] ?? null,
            'kind' => FlintDigestKind::for($event, $eventMeta),
            'title' => $eventMeta['title'] ?? $event->action,
            'summary' => $eventMeta['summary'] ?? null,
            // MR-7: the lede as its own field, so the client renders
            // `opener` verbatim and owns no knowledge of digest prose
            // structure. Falls back to a best-effort extraction for digests
            // written before the skill started sending one explicitly.
            'opener' => $eventMeta['opener'] ?? FlintDigestOpener::extract($eventMeta['summary'] ?? null),
            'created_at' => $event->created_at->toIso8601String(),
            'version' => app(ResourceVersion::class)->etag($event),
            'block_count' => $blocks->count(),
            'unanswered_question_count' => $blocks->filter(
                fn (array $b) => $b['block_type'] === 'flint_user_question'
                    && $b['status'] === 'open'
            )->count(),
            'blocks' => $blocks->values(),
        ];
    }
}
