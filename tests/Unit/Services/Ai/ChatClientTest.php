<?php

namespace Tests\Unit\Services\Ai;

use App\Models\User;
use App\Services\Ai\AiModel;
use App\Services\Ai\AiUsageContext;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\AiUsageReservation;
use App\Services\Ai\ChatClient;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fixtures\FakeOpenAi;
use Tests\Fixtures\RecordingTool;
use Tests\TestCase;

class ChatClientTest extends TestCase
{
    #[Test]
    public function without_tools_it_makes_a_single_call_and_returns_the_content(): void
    {
        FakeOpenAi::fake([FakeOpenAi::text('Hello there')]);

        $result = app(ChatClient::class)->text(AiModel::Reasoning, [
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'Say hi'],
        ]);

        $this->assertSame('Hello there', $result);
        $requests = FakeOpenAi::requests();
        $this->assertCount(1, $requests);
        $this->assertArrayNotHasKey('tools', $requests[0]->data());
        $this->assertFalse($requests[0]['store']);
        $this->assertStringContainsString('Be brief.', json_encode($requests[0]['input']));
        $this->assertStringContainsString('Say hi', json_encode($requests[0]['input']));
    }

    #[Test]
    public function json_mode_asks_for_a_json_object_and_decodes_it(): void
    {
        FakeOpenAi::fake([FakeOpenAi::text('{"total": 12.5}')]);

        $result = app(ChatClient::class)->json(AiModel::Extraction, [['role' => 'user', 'content' => 'Extract']]);

        $this->assertSame(['total' => 12.5], $result);
        $this->assertSame(['type' => 'json_object'], FakeOpenAi::requests()[0]['text']['format']);
    }

    #[Test]
    public function it_executes_a_tool_call_and_feeds_the_result_back_for_a_final_answer(): void
    {
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['search_thing', ['query' => 'Loki']]]),
            FakeOpenAi::text('{"match_id": 42}'),
        ]);
        $tool = new RecordingTool(['id' => 42, 'title' => 'Loki']);

        $result = app(ChatClient::class)->text(AiModel::Reasoning, [['role' => 'user', 'content' => 'Find the right match']], [
            'tools' => [$tool],
        ]);

        $this->assertSame('{"match_id": 42}', $result);
        $this->assertSame([['query' => 'Loki']], $tool->received);

        $requests = FakeOpenAi::requests();
        $this->assertCount(2, $requests);
        $this->assertSame('search_thing', $requests[0]['tools'][0]['name']);

        // The second request carries the tool call and its result.
        $outputs = collect($requests[1]['input'])->where('type', 'function_call_output')->values();
        $this->assertCount(1, $outputs);
        $this->assertSame(json_encode(['id' => 42, 'title' => 'Loki']), $outputs[0]['output']);
    }

    #[Test]
    public function it_stops_after_max_tool_rounds_and_returns_whatever_content_is_left(): void
    {
        // A model that keeps calling the tool forever - the cap must kick
        // in rather than looping indefinitely.
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['search_thing', ['query' => 'a']]], id: 'r1'),
            FakeOpenAi::toolCalls([['search_thing', ['query' => 'b']]], id: 'r2'),
            FakeOpenAi::toolCalls([['search_thing', ['query' => 'c']]], id: 'r3'),
        ]);
        $tool = new RecordingTool(['ok' => true]);

        $result = app(ChatClient::class)->text(AiModel::Reasoning, [['role' => 'user', 'content' => 'Find it']], [
            'tools' => [$tool],
            'max_tool_rounds' => 2,
        ]);

        // The 3rd response also requests a tool call, but the round cap is
        // already reached - so it's returned as-is (no content) rather than
        // executing a 3rd tool call.
        $this->assertSame('', $result);
        $this->assertCount(2, $tool->received);
        $this->assertCount(3, FakeOpenAi::requests());
    }

    #[Test]
    public function dotted_hyphenated_and_dated_reasoning_models_do_not_send_temperature(): void
    {
        $models = ['gpt-5.1', 'gpt-5-mini-2026-08-01', 'o3-mini', 'o4-pro-2026-05-10'];
        FakeOpenAi::fake(array_map(fn () => FakeOpenAi::text('ok'), $models));

        foreach ($models as $model) {
            config(['services.openai.models.reasoning' => $model]);

            app(ChatClient::class)->text(AiModel::Reasoning, [['role' => 'user', 'content' => 'Hi']], ['temperature' => 0.2]);

            $request = FakeOpenAi::requests()->last();
            $this->assertSame($model, $request['model']);
            $this->assertArrayNotHasKey('temperature', $request->data());
        }
    }

    #[Test]
    public function non_reasoning_models_keep_explicit_temperature_and_token_limits(): void
    {
        config(['services.openai.models.reasoning' => 'gpt-4.1-mini']);
        FakeOpenAi::fake([FakeOpenAi::text('ok')]);

        app(ChatClient::class)->text(AiModel::Reasoning, [['role' => 'user', 'content' => 'Hi']], [
            'temperature' => 0.2,
            'max_completion_tokens' => 300,
        ]);

        $request = FakeOpenAi::requests()[0];
        $this->assertSame(0.2, $request['temperature']);
        $this->assertSame(300, $request['max_output_tokens']);
    }

    #[Test]
    public function usage_is_recorded_from_the_sdk_response(): void
    {
        $user = User::factory()->create();
        $context = new AiUsageContext($user, 'chat');
        $reservation = new AiUsageReservation('reservation', 'event', 'gpt-5.1', today()->toDateString(), $context);
        $recorder = Mockery::mock(AiUsageRecorder::class);
        $recorder->shouldReceive('reserve')->once()->andReturn($reservation);
        $recorder->shouldReceive('complete')->once()->withArgs(
            fn ($reserved, $usage, $requestId) => $usage->inputTokens === 30 && $usage->outputTokens === 7 && $requestId === 'resp_text'
        );
        $this->app->instance(AiUsageRecorder::class, $recorder);
        FakeOpenAi::fake([FakeOpenAi::text('ok', ['input_tokens' => 30, 'output_tokens' => 7])]);

        app(ChatClient::class)->text(AiModel::Reasoning, [['role' => 'user', 'content' => 'Hi']], ['usage_context' => $context]);
    }

    #[Test]
    public function accounting_failure_after_a_provider_response_never_retries_the_provider(): void
    {
        $user = User::factory()->create();
        $context = new AiUsageContext($user, 'chat');
        $reservation = new AiUsageReservation('reservation', 'event', 'gpt-5.1', today()->toDateString(), $context);
        $recorder = Mockery::mock(AiUsageRecorder::class);
        $recorder->shouldReceive('reserve')->once()->andReturn($reservation);
        $recorder->shouldReceive('complete')->once()->andThrow(new RuntimeException('accounting unavailable'));
        $recorder->shouldNotReceive('fail');
        $this->app->instance(AiUsageRecorder::class, $recorder);
        FakeOpenAi::fake([FakeOpenAi::text('still succeeds')]);

        $result = app(ChatClient::class)->text(
            AiModel::Reasoning,
            [['role' => 'user', 'content' => 'Hi']],
            ['usage_context' => $context, 'retries' => 3],
        );

        $this->assertSame('still succeeds', $result);
        $this->assertCount(1, FakeOpenAi::requests());
    }
}
