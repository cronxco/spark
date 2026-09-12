<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\FlintDigestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Name('create-flint-digest')]
class CreateFlintDigestTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Create a Flint digest event with attached blocks.
        Use this to record an AI-generated digest, including user questions (flint_user_question)
        and editorial notes (flint_editorial_note) alongside standard content blocks.

        Block types:
        - `flint_user_question`: A question for the user. Provide `question`, optional `topic`,
          `priority` (low/medium/high), and optional `answer_options` array.
        - `flint_editorial_note`: Freeform AI commentary. Provide `content` (markdown).
        - `flint_day_context`: Structured calendar + weather for today, drawn from the same
          grounding calls used for the prose briefing. Provide `day_context` — an object with
          `calendar` (array of `{title, all_day, start, person}` for actual commitments; `person`
          is "will" or "dan": "dan" only when the title names Dan/Daniel and does not also name
          Will, "will" for everything else including an unspecified title — never omit `person`),
          `birthdays` (array of `{title}` — a birthday is not a commitment either of you is
          attending, so no `person` field; keep it out of `calendar`), and `weather`
          (`{location, condition, temp_high_c, rain_probability_pct}`). Do not put this in `content`.
        - `flint_news`: One story from the news roundup. Provide `content` (a short standalone
          distillation, not a copy of the summary section) and `referenced_event_ids`.
        - `flint_reading_pick` / `flint_reading_drop`: One item from the reading list. Provide
          `content` (why this, tonight), `url`, and for a pick `minutes` (a whole number).
        - `flint_insight`: A standalone observation. Provide `content` (markdown).

        Only these registered types are accepted; an unknown `flint_*` type is rejected rather
        than stored as an unrenderable block.

        Calls create a new digest. Do not retry after an unknown outcome without
        checking get-latest-flint-digest first. Routine callers must pass the
        encrypted `run_token` supplied in their trigger payload; retries with
        that token return the original digest.
    MARKDOWN;

    public function __construct(private FlintDigestService $digests) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'flint:write')) {
            return $error;
        }

        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        try {
            $payload = $request->all();

            // "today" is resolved by the service, in the user's own timezone.
            // Rewriting it here used the app timezone, so a digest written late
            // in a user's evening could be filed against the wrong local date.
            if (($payload['date'] ?? null) === 'today') {
                unset($payload['date']);
            }

            return Response::json($this->digests->create($user, $payload));
        } catch (ValidationException $exception) {
            return Response::error($exception->validator->errors()->first());
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Title for the digest (e.g. "Morning Digest — May 10").')
                ->required(),

            'period' => $schema->string()
                ->description('Time period: morning, afternoon, or evening. Inferred from current time if omitted.'),

            'date' => $schema->string()
                ->description('ISO date for the digest (e.g. "2026-05-10"). Defaults to today.')
                ->default('today'),

            'summary' => $schema->string()
                ->description('Optional headline summary content for the digest.'),

            'run_token' => $schema->string()
                ->description('Opaque run token from a scheduled or manual Flint trigger. Pass through unchanged.'),

            'blocks' => $schema->array()
                ->items($schema->object([
                    'block_type' => $schema->string()
                        ->required()
                        ->description('Block type (e.g. flint_user_question, flint_editorial_note, flint_insight).'),
                    'title' => $schema->string()
                        ->required()
                        ->description('Block title.'),
                    'content' => $schema->string()
                        ->description('Markdown content — for flint_editorial_note and other content blocks.'),
                    'url' => $schema->string()
                        ->description('Link this block points at — for flint_reading_pick and flint_reading_drop.'),
                    'minutes' => $schema->integer()
                        ->description('Estimated read time in whole minutes — for flint_reading_pick. A single number, not a range.'),
                    'referenced_event_ids' => $schema->array()
                        ->items($schema->string())
                        ->description('Event UUIDs this block draws on. Surfaced to the client as tappable reference chips and linkified inline in the content.'),
                    'question' => $schema->string()
                        ->description('For flint_user_question: the question text to display to the user.'),
                    'topic' => $schema->string()
                        ->description('For flint_user_question: category (e.g. health, money, routine).'),
                    'priority' => $schema->string()
                        ->description('For flint_user_question: low, medium, or high.'),
                    'answer_options' => $schema->array()
                        ->items($schema->string())
                        ->description('For flint_user_question: optional multiple-choice answers. Omit for freeform.'),
                    'day_context' => $schema->object([
                        'calendar' => $schema->array()
                            ->items($schema->object([
                                'title' => $schema->string()->required(),
                                'all_day' => $schema->boolean(),
                                'start' => $schema->string()
                                    ->description('ISO 8601 timestamp; omit for all-day entries.'),
                                'person' => $schema->string()
                                    ->enum(['will', 'dan'])
                                    ->required()
                                    ->description('"dan" only when the title names Dan/Daniel without also naming Will; "will" otherwise.'),
                            ]))
                            ->description('Today\'s calendar rows for the Day screen — actual commitments, not birthdays.'),
                        'birthdays' => $schema->array()
                            ->items($schema->object([
                                'title' => $schema->string()->required(),
                            ]))
                            ->description('Today\'s birthdays — title only, no person attribution.'),
                        'weather' => $schema->object([
                            'location' => $schema->string(),
                            'condition' => $schema->string(),
                            'temp_high_c' => $schema->number(),
                            'rain_probability_pct' => $schema->integer(),
                        ]),
                    ])->description('For flint_day_context: structured calendar + weather. See block-type notes above.'),
                ]))
                ->description('Blocks to attach to this digest.'),
        ];
    }
}
