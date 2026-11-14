<?php

namespace Tests\Feature\Flint;

use App\Ai\Tools\AskFlint\LogEventTool;
use App\Ai\Tools\AskFlint\TagEventTool;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Flint\Ask\AskFlintChangeLog;
use App\Services\Flint\Ask\AskFlintTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What an Ask Flint turn is handed, and the limits its write tools enforce
 * whatever the model asks for.
 */
class AskFlintToolsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_read_only_turn_is_given_no_write_tools(): void
    {
        $user = User::factory()->create();

        $tools = app(AskFlintTools::class)->for($user, new AskFlintChangeLog, canWrite: false);

        $this->assertCount(count(AskFlintTools::READ_TOOLS), $tools);
        $this->assertContainsOnlyInstancesOf(McpServerTool::class, $tools);
    }

    #[Test]
    public function a_writing_turn_is_given_exactly_the_named_write_tools(): void
    {
        $user = User::factory()->create();

        $tools = app(AskFlintTools::class)->for($user, new AskFlintChangeLog, canWrite: true);
        $written = collect($tools)
            ->reject(fn (object $tool): bool => $tool instanceof McpServerTool)
            ->map(fn (object $tool): string => $tool->name())
            ->values()
            ->all();

        $this->assertSame(AskFlintTools::writeToolNames(), $written);
    }

    #[Test]
    public function a_write_tool_refuses_when_the_token_cannot_write(): void
    {
        $user = User::factory()->create();
        $event = $this->eventFor($user);
        $actor = $this->actor($user, ['data:read']);
        $changes = new AskFlintChangeLog;

        $output = json_decode($this->tagTool($actor, $changes)->handle(new Request(['event_id' => $event->id, 'name' => 'lunch'])), true);

        $this->assertSame('failed', $output['status']);
        $this->assertSame([], $changes->all());
        $this->assertSame(0, $event->fresh()->tags()->count());
    }

    #[Test]
    public function a_turn_stops_changing_things_at_the_limit(): void
    {
        $user = User::factory()->create();
        $actor = $this->actor($user, ['data:read', 'data:write']);
        $changes = new AskFlintChangeLog;
        $tool = $this->tagTool($actor, $changes);

        foreach (range(1, AskFlintChangeLog::MAX_PER_TURN) as $index) {
            $output = json_decode($tool->handle(new Request(['event_id' => $this->eventFor($user)->id, 'name' => "tag {$index}"])), true);
            $this->assertSame('changed', $output['status']);
        }

        $oneTooMany = $this->eventFor($user);
        $output = json_decode($tool->handle(new Request(['event_id' => $oneTooMany->id, 'name' => 'extra'])), true);

        $this->assertSame('failed', $output['status']);
        $this->assertCount(AskFlintChangeLog::MAX_PER_TURN, $changes->all());
        $this->assertSame(0, $oneTooMany->fresh()->tags()->count());
    }

    #[Test]
    public function a_bad_argument_comes_back_as_a_message_not_an_exception(): void
    {
        $user = User::factory()->create();
        $changes = new AskFlintChangeLog;

        $output = json_decode($this->tagTool($this->actor($user, ['data:write']), $changes)->handle(new Request(['event_id' => 'not-a-uuid', 'name' => 'lunch'])), true);

        $this->assertSame('failed', $output['status']);
        $this->assertNotEmpty($output['error']);
        $this->assertSame([], $changes->all());
    }

    #[Test]
    public function a_log_that_breaks_the_log_rules_writes_nothing(): void
    {
        $user = User::factory()->create();
        $changes = new AskFlintChangeLog;
        $tool = app()->make(LogEventTool::class, ['user' => $this->actor($user, ['data:write']), 'changes' => $changes]);

        $noTitle = json_decode($tool->handle(new Request(['kind' => 'wine'])), true);
        $future = json_decode($tool->handle(new Request(['kind' => 'reflection', 'body' => 'Tomorrow went well.', 'occurred_at' => now()->addDay()->toIso8601String()])), true);

        $this->assertSame('failed', $noTitle['status']);
        $this->assertSame('failed', $future['status']);
        $this->assertSame('Nothing can be logged in the future.', $future['error']);
        $this->assertSame([], $changes->all());
        $this->assertSame(0, Event::query()->where('service', 'manual_log')->count());
    }

    /** @param  array<int, string>  $abilities */
    private function actor(User $user, array $abilities): User
    {
        $tokenModel = Sanctum::personalAccessTokenModel();

        return (clone $user)->withAccessToken(new $tokenModel(['name' => 'test', 'abilities' => $abilities]));
    }

    private function tagTool(User $actor, AskFlintChangeLog $changes): TagEventTool
    {
        return app()->make(TagEventTool::class, ['user' => $actor, 'changes' => $changes]);
    }

    private function eventFor(User $user): Event
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'monzo']);

        return Event::factory()->create(['integration_id' => $integration->id, 'service' => 'monzo', 'time' => now()->subDay()]);
    }
}
