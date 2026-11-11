<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\AskFlintRequest;
use App\Services\Ai\Exceptions\AiDailyTokenCapExceeded;
use App\Services\Flint\Ask\AskFlintConversationNotFound;
use App\Services\Flint\Ask\AskFlintService;
use App\Services\Flint\Ask\AskFlintTranscript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Ask Flint: a conversation with the user's Spark data that can also change
 * it. Changes apply at once and each carries an action receipt, undone with
 * `POST /actions/receipts/{id}/undo` like any other command.
 */
class FlintAskController extends Controller
{
    public function __construct(private AskFlintService $flint) {}

    /**
     * POST /api/v1/mobile/flint/ask
     *
     * Body: `message`, optional `conversation_id` to continue a thread, and
     * `stream`. With `stream` (or `Accept: text/event-stream`) the answer is
     * sent as server-sent events — `conversation`, `delta`, `activity`,
     * `change`, then `done` or `error`. Otherwise the whole answer comes back
     * as JSON, which is what a Siri intent with a short time budget wants.
     */
    public function store(AskFlintRequest $request): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        $message = trim((string) $request->validated('message'));
        $conversationId = $request->validated('conversation_id');

        try {
            if ($request->wantsStream()) {
                $frames = $this->flint->stream($user, $message, $conversationId);

                return response()->stream(function () use ($frames): void {
                    foreach ($frames as [$event, $data]) {
                        echo "event: {$event}\n";
                        echo 'data: ' . json_encode(['contract_version' => AskFlintTranscript::CONTRACT_VERSION] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }, 200, [
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache, no-transform',
                    'X-Accel-Buffering' => 'no',
                ]);
            }

            return response()->json(['contract_version' => AskFlintTranscript::CONTRACT_VERSION] + $this->flint->ask($user, $message, $conversationId));
        } catch (AskFlintConversationNotFound) {
            return $this->notFound();
        } catch (AiDailyTokenCapExceeded) {
            return response()->json([
                'message' => 'Flint has used today\'s AI allowance. Try again tomorrow.',
                'code' => 'daily_allowance_reached',
            ], 429);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Flint could not answer that just now.',
                'code' => 'flint_unavailable',
            ], 503);
        }
    }

    /** GET /api/v1/mobile/flint/ask/conversations?limit=&cursor= */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string'],
        ]);

        return response()->json(['contract_version' => AskFlintTranscript::CONTRACT_VERSION] + $this->flint->conversations(
            $request->user(),
            (int) ($validated['limit'] ?? 20),
            $validated['cursor'] ?? null,
        ));
    }

    /**
     * GET /api/v1/mobile/flint/ask/conversations/{id}?limit=&cursor=
     *
     * The newest messages, oldest first; the cursor pages back through the
     * thread. Each change carries its receipt's Undo state as it is now.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        try {
            return response()->json(['contract_version' => AskFlintTranscript::CONTRACT_VERSION, 'conversation_id' => $id] + $this->flint->messages(
                $request->user(),
                $id,
                (int) ($validated['limit'] ?? 30),
                $validated['cursor'] ?? null,
            ));
        } catch (AskFlintConversationNotFound) {
            return $this->notFound();
        }
    }

    /** DELETE /api/v1/mobile/flint/ask/conversations/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->flint->forget($request->user(), $id);
        } catch (AskFlintConversationNotFound) {
            return $this->notFound();
        }

        return response()->json(null, 204);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Conversation not found.'], 404);
    }
}
