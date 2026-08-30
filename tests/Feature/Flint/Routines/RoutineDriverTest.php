<?php

namespace Tests\Feature\Flint\Routines;

use App\Models\User;
use App\Services\Flint\Routines\OpenAiRoutineDriver;
use App\Services\Flint\Routines\RoutineDriverManager;
use App\Services\Flint\Routines\WebhookRoutineDriver;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoutineDriverTest extends TestCase
{
    #[Test]
    public function the_default_driver_is_the_webhook_so_merging_changes_nothing(): void
    {
        config(['services.flint_routine.driver' => 'webhook', 'services.flint_routine.routines.topics.driver' => null]);

        $manager = new RoutineDriverManager;

        $this->assertSame('webhook', $manager->driverName('topics'));
        $this->assertInstanceOf(WebhookRoutineDriver::class, $manager->for('topics'));
    }

    #[Test]
    public function a_routine_can_override_the_default_driver(): void
    {
        config([
            'services.flint_routine.driver' => 'webhook',
            'services.flint_routine.routines.news_roundup.driver' => 'openai',
        ]);

        $manager = new RoutineDriverManager;

        $this->assertSame('webhook', $manager->driverName('topics'));
        $this->assertSame('openai', $manager->driverName('news_roundup'));
        $this->assertInstanceOf(OpenAiRoutineDriver::class, $manager->for('news_roundup'));
    }

    #[Test]
    public function an_unconfigured_webhook_is_not_applicable_rather_than_a_failure(): void
    {
        config(['services.flint_routine.routines.topics.url' => null]);

        $result = (new WebhookRoutineDriver)->run(User::factory()->create(), 'topics', []);

        $this->assertSame('not_applicable', $result->status);
    }

    #[Test]
    public function the_openai_driver_is_not_applicable_without_a_cronxtools_url(): void
    {
        config(['services.flint_routine.cronxtools_url' => null]);

        $result = app(OpenAiRoutineDriver::class)->run(User::factory()->create(), 'topics', []);

        $this->assertSame('not_applicable', $result->status);
    }

    #[Test]
    public function the_openai_driver_is_not_applicable_for_a_routine_with_no_vendored_skill(): void
    {
        config(['services.flint_routine.cronxtools_url' => 'https://mcp.example.test/abc123/sse']);

        $result = app(OpenAiRoutineDriver::class)->run(User::factory()->create(), 'not_a_routine', []);

        $this->assertSame('not_applicable', $result->status);
    }

    #[Test]
    public function the_openai_driver_sends_the_skill_body_and_only_its_allowed_tools(): void
    {
        config([
            'services.flint_routine.cronxtools_url' => 'https://mcp.example.test/secret-token/sse',
            'services.openai.api_key' => 'test-key',
            'services.openai.models.reasoning' => 'test-reasoning-model',
        ]);

        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'output' => [
                ['type' => 'mcp_call', 'name' => 'spark__manage-flint-topic', 'output' => 'ok'],
                ['type' => 'message', 'content' => [['text' => 'Done.']]],
            ],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
        ])]);

        $result = app(OpenAiRoutineDriver::class)->run(User::factory()->create(), 'topics', ['routine' => 'topics']);

        $this->assertSame('success', $result->status);
        $this->assertSame('openai', $result->details['driver']);
        $this->assertSame(1, $result->details['tool_calls']);
        $this->assertSame(120, $result->details['input_tokens']);

        Http::assertSent(function ($request) {
            $tool = $request['tools'][0];

            $this->assertSame('mcp', $tool['type']);
            $this->assertSame('never', $tool['require_approval']);
            $this->assertSame('https://mcp.example.test/secret-token/sse', $tool['server_url']);
            $this->assertStringContainsString('Flint Topics', $request['instructions']);

            // The topics skill must not be handed anything beyond its manifest.
            $this->assertSame([
                'spark__get-events-by-filter-tool',
                'spark__get-latest-flint-digest',
                'spark__manage-flint-topic',
            ], $tool['allowed_tools']);

            return true;
        });
    }
}
