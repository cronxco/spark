<?php

namespace Tests\Feature\Actions;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricTrendsJob;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\Actions\ActionExecutor;
use App\Services\Actions\ActionRegistry;
use App\Services\Actions\ContextualActions;
use App\Spotlight\Queries\Actions\ContextualActionsQuery;
use App\Spotlight\Queries\Actions\GlobalActionsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The commands a page offers come from the ActionRegistry, with the record
 * they act on, and run through the same executor as ">" mode.
 */
class ContextualActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.enable_task_pipeline' => false]);
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    #[Test]
    public function the_metrics_page_offers_calculate_and_detect(): void
    {
        $this->assertSame(
            ['metrics.calculate', 'metrics.detect_trends'],
            $this->offered('metrics.index', []),
        );
    }

    #[Test]
    public function a_metric_offers_acknowledge_only_while_trends_are_waiting(): void
    {
        $metric = MetricStatistic::factory()->create(['id' => (string) Str::uuid(), 'user_id' => $this->alice->id]);

        $this->assertSame(['metrics.calculate'], $this->offered('metrics.show', ['metric' => $metric]));

        $trend = MetricTrend::factory()->create(['id' => (string) Str::uuid(), 'metric_statistic_id' => $metric->id, 'acknowledged_at' => null]);

        $this->assertSame(['metrics.calculate', 'metrics.acknowledge_trends'], $this->offered('metrics.show', ['metric' => $metric]));

        $trend->acknowledge();

        $this->assertSame(['metrics.calculate'], $this->offered('metrics.show', ['metric' => $metric]));
    }

    #[Test]
    public function another_users_metric_offers_no_acknowledge(): void
    {
        $metric = MetricStatistic::factory()->create(['id' => (string) Str::uuid(), 'user_id' => $this->bob->id]);
        MetricTrend::factory()->create(['id' => (string) Str::uuid(), 'metric_statistic_id' => $metric->id, 'acknowledged_at' => null]);

        $this->assertNotContains('metrics.acknowledge_trends', $this->offered('metrics.show', ['metric' => $metric]));
    }

    #[Test]
    public function an_integration_offers_pause_while_running_and_resume_while_paused(): void
    {
        $integration = $this->integrationFor($this->alice);

        $this->assertSame(
            ['integrations.sync', 'integrations.pause', 'integrations.open_configure'],
            $this->offered('integrations.details', ['integration' => $integration]),
        );

        $integration->update(['configuration' => ['paused' => true]]);

        $this->assertSame(
            ['integrations.resume', 'integrations.open_configure'],
            $this->offered('integrations.details', ['integration' => $integration->fresh()]),
        );
    }

    #[Test]
    public function another_users_integration_offers_nothing_that_runs(): void
    {
        $integration = $this->integrationFor($this->bob);

        $this->assertSame(['integrations.open_configure'], $this->offered('integrations.details', ['integration' => $integration]));
    }

    #[Test]
    public function an_account_page_offers_its_three_modals(): void
    {
        $this->assertSame(
            ['money.open_add_balance', 'money.open_edit', 'money.open_archive'],
            $this->offered('money.show', ['account' => (string) fake()->uuid()]),
        );
    }

    #[Test]
    public function an_action_with_no_value_for_its_target_is_not_offered(): void
    {
        $this->assertNotContains('integrations.sync', $this->offered('integrations.details', []));
    }

    #[Test]
    public function page_actions_stay_out_of_the_global_menu(): void
    {
        $global = GlobalActionsQuery::visible()->pluck('id')->all();

        foreach (['metrics.calculate', 'metrics.detect_trends', 'integrations.sync', 'integrations.pause', 'money.open_edit'] as $id) {
            $this->assertNotContains($id, $global);
        }
    }

    #[Test]
    public function a_server_action_fires_run_action_with_its_target(): void
    {
        $integration = $this->integrationFor($this->alice);
        $contextual = app(ContextualActions::class)->for($this->alice, 'integrations.details', ['integration' => $integration])
            ->firstWhere(fn (array $contextual): bool => $contextual['action']->id === 'integrations.pause');

        $this->assertSame(
            ['name' => 'run-action', 'data' => ['action' => 'integrations.pause', 'target' => ['integration_id' => $integration->id]], 'close' => true],
            ContextualActionsQuery::dispatch($contextual['action'], $contextual['target']),
        );
    }

    #[Test]
    public function a_modal_action_fires_the_pages_event(): void
    {
        $archive = app(ActionRegistry::class)->find('money.open_archive');

        $this->assertSame(['name' => 'open-archive-modal', 'data' => [], 'close' => true], ContextualActionsQuery::dispatch($archive, []));
    }

    #[Test]
    public function spotlight_filters_page_actions_by_the_query(): void
    {
        $this->actingAs($this->alice);

        $this->assertCount(2, ContextualActionsQuery::results('metrics.index', [], ''));
        $this->assertCount(1, ContextualActionsQuery::results('metrics.index', [], 'detect'));
        $this->assertCount(0, ContextualActionsQuery::results('metrics.index', [], 'nothing like this'));
    }

    #[Test]
    public function spotlight_offers_nothing_to_a_guest(): void
    {
        $this->assertCount(0, ContextualActionsQuery::results('metrics.index', [], ''));
    }

    #[Test]
    public function the_layout_runs_calculate_for_the_signed_in_user_only(): void
    {
        Queue::fake();

        Volt::actingAs($this->alice)->test('global-progress-indicator')
            ->dispatch('run-action', action: 'metrics.calculate')
            ->dispatch('run-action', action: 'metrics.detect_trends');

        Queue::assertPushed(CalculateMetricStatisticsJob::class, fn ($job) => $job->userId === $this->alice->id);
        Queue::assertPushed(DetectMetricTrendsJob::class, fn ($job) => $job->userId === $this->alice->id);
    }

    #[Test]
    public function the_layout_pauses_an_integration_and_the_receipt_can_be_undone(): void
    {
        $integration = $this->integrationFor($this->alice);

        Volt::actingAs($this->alice)->test('global-progress-indicator')
            ->dispatch('run-action', action: 'integrations.pause', target: ['integration_id' => $integration->id])
            ->assertDispatched('action-ran', action: 'integrations.pause');

        $this->assertTrue($integration->fresh()->isPaused());
    }

    #[Test]
    public function the_layout_will_not_act_on_another_users_record(): void
    {
        $theirs = $this->integrationFor($this->bob);

        Volt::actingAs($this->alice)->test('global-progress-indicator')
            ->dispatch('run-action', action: 'integrations.pause', target: ['integration_id' => $theirs->id]);

        $this->assertFalse($theirs->fresh()->isPaused());
    }

    #[Test]
    public function the_layout_ignores_a_page_action_sent_without_its_target(): void
    {
        Queue::fake();

        Volt::actingAs($this->alice)->test('global-progress-indicator')
            ->dispatch('run-action', action: 'integrations.sync')
            ->assertNotDispatched('action-ran');
    }

    #[Test]
    public function the_layout_ignores_an_interface_action(): void
    {
        Volt::actingAs($this->alice)->test('global-progress-indicator')
            ->dispatch('run-action', action: 'money.open_edit')
            ->assertNotDispatched('action-ran');
    }

    #[Test]
    public function acknowledging_trends_marks_each_one_and_undo_restores_them(): void
    {
        $metric = MetricStatistic::factory()->create(['id' => (string) Str::uuid(), 'user_id' => $this->alice->id]);
        $trends = MetricTrend::factory()->count(2)->state(fn () => ['id' => (string) Str::uuid()])->create(['metric_statistic_id' => $metric->id, 'acknowledged_at' => null]);
        $already = MetricTrend::factory()->create(['id' => (string) Str::uuid(), 'metric_statistic_id' => $metric->id, 'acknowledged_at' => now()->subDay()]);

        $executor = app(ActionExecutor::class);
        $receipt = $executor->run($this->alice, app(ActionRegistry::class)->find('metrics.acknowledge_trends'), ['metric_id' => $metric->id]);

        $this->assertSame('succeeded', $receipt->status()->value);
        $this->assertSame(2, $receipt->command('result.acknowledged'));
        $this->assertTrue($trends->every(fn ($trend) => $trend->fresh()->acknowledged_at !== null));
        $this->assertTrue($receipt->canUndo());

        $executor->undo($this->alice, $receipt->id());

        $this->assertTrue($trends->every(fn ($trend) => $trend->fresh()->acknowledged_at === null));
        $this->assertNotNull($already->fresh()->acknowledged_at, 'a trend acknowledged before the run stays acknowledged');
    }

    #[Test]
    public function acknowledging_another_users_metric_fails_and_changes_nothing(): void
    {
        $metric = MetricStatistic::factory()->create(['id' => (string) Str::uuid(), 'user_id' => $this->bob->id]);
        $trend = MetricTrend::factory()->create(['id' => (string) Str::uuid(), 'metric_statistic_id' => $metric->id, 'acknowledged_at' => null]);

        $receipt = app(ActionExecutor::class)->run($this->alice, app(ActionRegistry::class)->find('metrics.acknowledge_trends'), ['metric_id' => $metric->id]);

        $this->assertSame('failed', $receipt->status()->value);
        $this->assertNull($trend->fresh()->acknowledged_at);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<int, string>
     */
    private function offered(string $route, array $parameters): array
    {
        return app(ContextualActions::class)->for($this->alice, $route, $parameters)
            ->map(fn (array $contextual): string => $contextual['action']->id)
            ->all();
    }

    private function integrationFor(User $user): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'spotify']);

        return Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'spotify']);
    }
}
