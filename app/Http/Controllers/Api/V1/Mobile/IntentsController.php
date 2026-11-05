<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Flint\FlintNoteService;
use App\Services\QuickLog\IntentClassifier;
use App\Services\QuickLog\IntentTelemetry;
use App\Services\QuickLog\QuickLogIntent;
use App\Support\SparkAbility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

class IntentsController extends Controller
{
    public function __construct(
        private IntentClassifier $classifier,
        private IntentTelemetry $telemetry,
    ) {}

    /** Classifies a line. Writes nothing. */
    public function classify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'hint' => ['nullable', 'array'],
            'hint.intent' => ['required_with:hint', Rule::in([...QuickLogIntent::choosable(), QuickLogIntent::Unsure->value])],
            'hint.confidence' => ['required_with:hint', 'numeric', 'between:0,1'],
        ]);

        return response()->json($this->classifier->classify($request->user()->id, $validated['text'], $validated['hint'] ?? null));
    }

    /**
     * Commits the intent the user settled on. Only a note for Flint is written here; log, command and search are
     * carried out by the client (log via POST /log with its structured draft), so they are acknowledged and counted.
     */
    public function commit(Request $request, FlintNoteService $notes): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'text' => ['required', 'string', 'max:2000'],
            'intent' => ['required', Rule::in(QuickLogIntent::choosable())],
            'suggested_intent' => ['nullable', Rule::in([...QuickLogIntent::choosable(), QuickLogIntent::Unsure->value])],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'occurred_at' => ['required', 'date', 'before_or_equal:' . now()->addMinutes(5)->toIso8601String()],
        ]);

        $user = $request->user();
        $text = trim($validated['text']);
        $intent = $validated['intent'];

        if ($this->classifier->hasFlintHandle($text) && $intent !== QuickLogIntent::FlintNote->value) {
            return response()->json([
                'message' => '@flint always makes a note for Flint.',
                'errors' => ['intent' => ['@flint always makes a note for Flint.']],
            ], 422);
        }

        $suggested = $validated['suggested_intent'] ?? null;
        $source = match (true) {
            $suggested === null || $suggested === QuickLogIntent::Unsure->value => 'picked',
            $suggested === $intent => 'accepted',
            default => 'override',
        };

        $response = ['status' => 'client_action', 'intent' => $intent];

        if ($intent === QuickLogIntent::FlintNote->value) {
            if (! SparkAbility::allows($user, 'flint:write')) {
                abort(403, 'This session cannot write notes for Flint.');
            }

            $body = $this->classifier->flintBody($text);
            if ($body === '') {
                return response()->json([
                    'message' => 'Say something to Flint.',
                    'errors' => ['text' => ['Say something to Flint.']],
                ], 422);
            }

            $result = $notes->create($user, [
                'client_mutation_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'spark:quick-log-intent:' . $user->id . ':' . $validated['idempotency_key']),
                'authored_at' => $validated['occurred_at'],
                'body' => $body,
                'consent_version' => FlintNoteService::CONSENT_VERSION,
            ]);

            if (! isset($result['note'])) {
                return response()->json(['message' => $result['message']], $result['status']);
            }

            $payload = $notes->payload($result['note']);
            $response = ['status' => 'saved', 'intent' => $intent, 'created' => $result['status'] === 201, 'note' => $payload];
            $status = $result['status'];
        }

        // Replays of an idempotent commit are not counted twice.
        if (($status ?? 200) !== 200 || $intent !== QuickLogIntent::FlintNote->value) {
            $this->telemetry->record($user->id, [
                'suggested' => $suggested,
                'chosen' => $intent,
                'source' => $source,
                'confidence' => isset($validated['confidence']) ? (float) $validated['confidence'] : null,
                'text_length' => Str::length($text),
                'mentions' => count($this->classifier->mentions($text)),
            ]);
        }

        return response()->json($response, $status ?? 200);
    }
}
