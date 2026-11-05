<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Money\PlanSnapshot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-money-plan')]
#[IsIdempotent]
#[IsReadOnly]
class GetMoneyPlanTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        The Money Plan as shown on the Plan tab: safe to spend, card cover (what the cards owe against
        the buffer pot), budget progress for this month, the month strip, pot balances and the last
        twelve months of interest. All amounts are integer pence. Read-only. Requires `finance:read`.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'finance:read')) {
            return $error;
        }

        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        return Response::json(app(PlanSnapshot::class)->build((string) $user->id));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
