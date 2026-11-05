<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Flint\FlintRunCompletionService;
use App\Services\Flint\FlintRunToken;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Name('complete-flint-run')]
class CompleteFlintRunTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'Mark a non-digest Flint routine complete using the verified run token supplied in its trigger payload.';

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'flint:write')) {
            return $error;
        }
        $token = $request->get('run_token');
        if (! is_string($token) || $token === '') {
            return Response::error('run_token is required.');
        }
        $outputId = $request->get('output_id');
        if (! is_string($outputId) || $outputId === '') {
            return Response::error('output_id is required.');
        }

        try {
            $claims = app(FlintRunToken::class)->verifyCompletion($token, $request->user());
            if (($claims['routine'] ?? null) !== 'topics') {
                return Response::error('Only the topics routine requires explicit completion.');
            }

            return Response::json(app(FlintRunCompletionService::class)->complete($request->user(), $claims, $outputId));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_token' => $schema->string()->description('Opaque run token from the Flint trigger payload.')->required(),
            'output_id' => $schema->string()->description('UUID of a persisted topic written by this run.')->required(),
        ];
    }
}
