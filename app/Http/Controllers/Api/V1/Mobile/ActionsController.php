<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Actions\ActionDefinition;
use App\Services\Actions\ActionExecutor;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionReceipt;
use App\Services\Actions\ActionRegistry;
use App\Services\Actions\ActionStatus;
use App\Services\Actions\ConfirmationRequired;
use App\Support\SparkAbility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The native face of the ActionRegistry. Listing, running and undoing all go
 * through the registry, so a command's capability and confirm policy are
 * declared once and enforced the same way for every client.
 */
class ActionsController extends Controller
{
    public function __construct(
        private ActionRegistry $registry,
        private ActionExecutor $executor,
    ) {}

    /** GET /api/v1/mobile/actions?q= */
    public function index(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $user = $request->user();

        $actions = $this->registry->forSurface('native')
            ->filter(fn (ActionDefinition $action): bool => $action->capability === null || SparkAbility::allows($user, $action->capability))
            ->filter(fn (ActionDefinition $action): bool => $action->matches($query))
            ->map(fn (ActionDefinition $action): array => $action->toArray())
            ->values();

        return response()->json(['contract_version' => 1, 'data' => $actions]);
    }

    /**
     * POST /api/v1/mobile/actions/{action}/run
     *
     * Body: `target` (the action's fields) and `confirmed`. `Idempotency-Key`
     * replays the first receipt, so a retry on a bad connection never runs
     * the action twice. 202 means queued, 409 means ask the user first.
     */
    public function run(Request $request, string $action): JsonResponse
    {
        $definition = $this->registry->forSurface('native')->get($action);

        if ($definition === null) {
            return response()->json(['message' => 'Action not found.'], 404);
        }

        if ($definition->capability !== null && ! SparkAbility::allows($request->user(), $definition->capability)) {
            return response()->json([
                'message' => 'Token lacks the required capability.',
                'required_ability' => $definition->capability,
            ], 403);
        }

        if ($definition->isNavigation()) {
            return response()->json([
                'message' => 'The client runs this action.',
                'code' => 'client_action',
                'action' => $definition->toArray(),
            ], 422);
        }

        $request->validate(['target' => ['sometimes', 'array'], 'confirmed' => ['sometimes', 'boolean']]);

        try {
            $receipt = $this->executor->run(
                $request->user(),
                $definition,
                (array) $request->input('target', []),
                $request->boolean('confirmed'),
                $request->header('Idempotency-Key') ?: null,
            );
        } catch (ConfirmationRequired) {
            return response()->json([
                'message' => 'Confirm to run this action.',
                'code' => 'confirmation_required',
                'action' => $definition->toArray(),
            ], 409);
        }

        return $this->respond($receipt);
    }

    /** GET /api/v1/mobile/actions/receipts/{id} */
    public function receipt(Request $request, string $id): JsonResponse
    {
        $receipt = ActionReceipt::find($request->user(), $id);

        return $receipt === null
            ? response()->json(['message' => 'Receipt not found.'], 404)
            : response()->json($receipt->toArray());
    }

    /** POST /api/v1/mobile/actions/receipts/{id}/undo */
    public function undo(Request $request, string $id): JsonResponse
    {
        try {
            $receipt = $this->executor->undo($request->user(), $id);
        } catch (ActionFailed $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->failureCode], $e->failureCode === 'not_found' ? 404 : 422);
        }

        return response()->json($receipt->toArray());
    }

    private function respond(ActionReceipt $receipt): JsonResponse
    {
        $status = match ($receipt->status()) {
            ActionStatus::Failed => 422,
            ActionStatus::Accepted, ActionStatus::Running => 202,
            ActionStatus::Succeeded => 200,
        };

        return response()->json($receipt->toArray(), $status);
    }
}
