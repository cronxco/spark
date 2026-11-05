<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\GetMoneyPlanTool;
use App\Models\User;
use App\Services\Ai\SkillRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetMoneyPlanToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_the_plan_snapshot_for_the_user(): void
    {
        $user = User::factory()->create();

        SparkServer::actingAs($user)->tool(GetMoneyPlanTool::class)
            ->assertOk()
            ->assertSee('safe_to_spend')
            ->assertSee('owed_on_cards')
            ->assertSee('budgets')
            ->assertSee('interest');
    }

    #[Test]
    public function the_vendored_money_skill_loads_with_its_plan_tool_approved(): void
    {
        $skill = app(SkillRegistry::class)->get('flint-money');

        $this->assertContains('spark__get-money-plan', SkillRegistry::APPROVED_TOOLS);
        $this->assertContains('spark__get-money-plan', $skill->allowedTools);
        $this->assertSame(['spark__create-flint-digest'], $skill->requiredSuccessTools);
    }
}
