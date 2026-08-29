<?php

namespace Tests\Unit\Services;

use App\Services\AssistantPromptingService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssistantPromptingServiceToolCallingTest extends TestCase
{
    #[Test]
    public function without_tools_it_makes_a_single_call_and_returns_the_content(): void
    {
        $fake = OpenAI::fake([
            $this->response('Hello there'),
        ]);

        $result = app(AssistantPromptingService::class)->generateResponse('Say hi');

        $this->assertSame('Hello there', $result);
        $fake->assertSent(Chat::class, 1);
        $fake->assertSent(Chat::class, fn ($method, $args) => ! array_key_exists('tools', $args));
    }

    #[Test]
    public function it_executes_a_tool_call_and_feeds_the_result_back_for_a_final_answer(): void
    {
        $fake = OpenAI::fake([
            $this->response(null, [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_thing',
                        'arguments' => json_encode(['query' => 'Loki']),
                    ],
                ],
            ]),
            $this->response('{"match_id": 42}'),
        ]);

        $received = [];
        $toolExecutor = function (string $name, array $arguments) use (&$received) {
            $received[] = [$name, $arguments];

            return ['id' => 42, 'title' => 'Loki'];
        };

        $result = app(AssistantPromptingService::class)->generateResponse('Find the right match', [
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_thing',
                        'description' => 'Searches for a thing',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => ['query' => ['type' => 'string']],
                            'required' => ['query'],
                        ],
                    ],
                ],
            ],
            'tool_executor' => $toolExecutor,
        ]);

        $this->assertSame('{"match_id": 42}', $result);
        $this->assertSame([['search_thing', ['query' => 'Loki']]], $received);
        $fake->assertSent(Chat::class, 2);

        // Second request must carry the assistant tool-call message and the
        // tool result message appended to the conversation.
        $fake->assertSent(Chat::class, function ($method, $args) {
            $messages = $args['messages'];

            return count($messages) === 3
                && $messages[1]['role'] === 'assistant'
                && $messages[1]['tool_calls'][0]['id'] === 'call_1'
                && $messages[2]['role'] === 'tool'
                && $messages[2]['tool_call_id'] === 'call_1'
                && $messages[2]['content'] === json_encode(['id' => 42, 'title' => 'Loki']);
        });
    }

    #[Test]
    public function it_stops_after_max_tool_rounds_and_returns_whatever_content_is_left(): void
    {
        // A model that keeps calling the tool forever - the cap must kick
        // in rather than looping indefinitely.
        $fake = OpenAI::fake([
            $this->response(null, [$this->toolCall('call_1', 'search_thing', ['query' => 'a'])]),
            $this->response(null, [$this->toolCall('call_2', 'search_thing', ['query' => 'b'])]),
            $this->response(null, [$this->toolCall('call_3', 'search_thing', ['query' => 'c'])]),
        ]);

        $executions = 0;

        $result = app(AssistantPromptingService::class)->generateResponse('Find it', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'search_thing', 'parameters' => []]]],
            'tool_executor' => function (string $name, array $arguments) use (&$executions) {
                $executions++;

                return ['ok' => true];
            },
            'max_tool_rounds' => 2,
        ]);

        // The 3rd response also requests a tool call, but the round cap is
        // already reached - so it's returned as-is (no content) rather than
        // executing a 3rd tool call.
        $this->assertSame('', $result);
        $this->assertSame(2, $executions);
        $fake->assertSent(Chat::class, 3);
    }

    #[Test]
    public function without_a_tool_executor_a_tool_call_response_is_treated_as_the_final_result(): void
    {
        $fake = OpenAI::fake([
            $this->response(null, [$this->toolCall('call_1', 'search_thing', ['query' => 'a'])]),
        ]);

        $result = app(AssistantPromptingService::class)->generateResponse('Find it', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'search_thing', 'parameters' => []]]],
        ]);

        $this->assertSame('', $result);
        $fake->assertSent(Chat::class, 1);
    }

    private function toolCall(string $id, string $name, array $arguments): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments),
            ],
        ];
    }

    private function response(?string $content, ?array $toolCalls = null): CreateResponse
    {
        $message = [
            'role' => 'assistant',
            'content' => $content,
        ];

        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
        }

        return CreateResponse::fake([
            'choices' => [
                [
                    'message' => $message,
                ],
            ],
        ]);
    }
}
