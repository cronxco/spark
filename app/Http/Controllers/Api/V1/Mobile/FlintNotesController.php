<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\EventObject;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\FlintNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class FlintNotesController extends Controller
{
    public function index(Request $request, FlintNoteService $notes, EffectiveTimezoneResolver $timezones): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string'],
            'unacknowledged' => ['nullable', 'boolean'],
        ]);
        $paginator = EventObject::query()
            ->where('user_id', $request->user()->id)
            ->where('concept', 'document')
            ->where('type', 'flint_note')
            ->when($request->boolean('unacknowledged'), fn ($query) => $query->whereNull('metadata->acknowledged_at'))
            ->with(['relationshipsFrom' => fn ($query) => $query->where('type', FlintNoteService::RELATIONSHIP_TYPE)])
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['limit'] ?? 20), ['*'], 'cursor');

        return response()->json([
            'data' => collect($paginator->items())->map(fn (EventObject $note) => $notes->payload($note))->all(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
            'meta' => [
                'effective_timezone' => $timezones->timezoneFor($request->user()),
                'account_id' => (string) $request->user()->id,
            ],
        ]);
    }

    public function store(Request $request, FlintNoteService $notes): JsonResponse
    {
        $validated = $request->validate([
            'client_mutation_id' => ['required', 'uuid'],
            'authored_at' => ['required', 'date', 'before_or_equal:' . now()->addMinutes(5)->toIso8601String()],
            'body' => ['required', 'string', 'max:10000'],
            'context_links' => ['nullable', 'array', 'max:20'],
            'context_links.*.type' => ['required', Rule::in(['event', 'digest', 'block', 'topic'])],
            'context_links.*.id' => ['required', 'uuid'],
            'consent_version' => ['required', Rule::in([FlintNoteService::CONSENT_VERSION])],
        ]);
        $result = $notes->create($request->user(), $validated);
        if (! isset($result['note'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        $payload = $notes->payload($result['note']);

        return response()->json(['data' => $payload], $result['status'])->header('ETag', $payload['version']);
    }

    public function acknowledge(Request $request, FlintNoteService $notes): JsonResponse
    {
        $validated = $request->validate([
            'note_ids' => ['required', 'array', 'min:1', 'max:50'],
            'note_ids.*' => ['required', 'uuid'],
            'routine' => ['nullable', 'string', 'max:64'],
            'run_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $notes->acknowledge(
            $request->user(),
            $validated['note_ids'],
            array_filter(Arr::only($validated, ['routine', 'run_uuid'])),
        )]);
    }

    public function destroy(Request $request, string $id, FlintNoteService $notes): JsonResponse
    {
        $notes->delete($request->user(), $id);

        return response()->json(null, 204);
    }
}
