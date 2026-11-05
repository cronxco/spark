<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Flint\FlintNoteService;
use App\Services\Flint\FlintRunToken;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use RuntimeException;

#[Name('acknowledge-flint-notes')]
#[IsIdempotent]
class AcknowledgeFlintNotesTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Mark Notes to Flint as read by a routine. Call this after reading notes with
        get-flint-notes (unacknowledged: true) and taking them into account. Pass the
        routine's run_token when its trigger payload has one so the acknowledgement is
        tied to that run; otherwise pass routine with its name. Notes that were already
        acknowledged keep their original acknowledgement.
    MARKDOWN;

    public function __construct(private FlintNoteService $notes) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'flint:write')) {
            return $error;
        }

        $user = $request->user();
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $ids = $request->get('note_ids');
        if (! is_array($ids) || $ids === [] || count($ids) > 50 || collect($ids)->contains(fn ($id) => ! is_string($id) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id))) {
            return Response::error('note_ids must be 1-50 note UUIDs.');
        }

        $by = [];
        $token = $request->get('run_token');
        if (is_string($token) && $token !== '') {
            try {
                $claims = app(FlintRunToken::class)->verifyCompletion($token, $user);
            } catch (RuntimeException $exception) {
                return Response::error($exception->getMessage());
            }
            $by = array_filter([
                'routine' => is_string($claims['routine'] ?? null) ? $claims['routine'] : null,
                'run_uuid' => $claims['run_uuid'],
            ]);
        }

        $routine = $request->get('routine');
        if (! isset($by['routine']) && is_string($routine) && trim($routine) !== '') {
            $by['routine'] = mb_substr(trim($routine), 0, 64);
        }

        return Response::json($this->notes->acknowledge($user, $ids, $by));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'note_ids' => $schema->array()->items($schema->string())
                ->description('UUIDs of the Notes to Flint to acknowledge, 1-50.')
                ->required(),
            'run_token' => $schema->string()
                ->description('Opaque run token from the routine trigger payload, if it has one.'),
            'routine' => $schema->string()
                ->description('Name of the acknowledging routine (e.g. digest, topics, reading_list). Used when there is no run_token.'),
        ];
    }
}
