<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\StoreLogRequest;
use App\Models\Event;
use App\Models\User;
use App\Services\Actions\ActionExecutor;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\ActionReceipt;
use App\Services\Actions\ActionRegistry;
use App\Services\ManualLog\ManualLogService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class LogController extends Controller
{
    public function __construct(
        protected ManualLogService $logs,
        protected ActionRegistry $registry,
        protected ActionExecutor $executor,
    ) {}

    public function store(StoreLogRequest $request): JsonResponse
    {
        try {
            $result = $request->boolean('dry_run')
                ? $this->logs->preview($request->user(), $request->validated())
                : $this->logs->log($request->user(), $request->validated());
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['title' => [$exception->getMessage()]],
            ], 422);
        }

        if (in_array($result['status'], ['needs_clarification', 'preview'], true)) {
            return response()->json($result);
        }

        $receipt = $request->validated('kind') === 'reflection'
            ? $this->reflectionReceipt($request->user(), $result, $request->validated('idempotency_key'))
            : null;

        return response()->json([
            'status' => 'logged',
            'events' => array_map(fn (Event $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'title' => $event->target?->title,
                'time' => $event->time?->toIso8601String(),
            ], $result['events']),
            'missing_episodes' => $result['missing_episodes'] ?? [],
            'people' => $result['people'] ?? [],
        ] + ($receipt === null ? [] : ['receipt' => $receipt->toArray()]), $result['created'] ? 201 : 200);
    }

    /**
     * The receipt that offers Undo for a reflection, on the same contract as
     * a registry command. A retry gets the first receipt back, undone or not.
     *
     * @param  array<string, mixed>  $result
     */
    private function reflectionReceipt(User $user, array $result, string $idempotencyKey): ?ActionReceipt
    {
        $action = $this->registry->find('events.log_reflection');

        if ($action === null) {
            return null;
        }

        if (! $result['created']) {
            return $this->executor->replay($user, $action, $idempotencyKey);
        }

        $event = $result['events'][0];
        $created = collect($result['people'] ?? [])->where('created', true)->pluck('id')->values()->all();
        $target = ['event_id' => $event->id];

        return $this->executor->record(
            $user,
            $action,
            $target,
            ActionOutcome::succeeded('Reflection logged', $target, $target + [
                'object_id' => $event->target_id,
                'created_person_ids' => $created,
            ]),
            $idempotencyKey,
        );
    }
}
