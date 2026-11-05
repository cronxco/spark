<?php

namespace Tests\Feature\Money;

use App\Jobs\Money\ApplyAccountCleanup;
use App\Livewire\Money\DataHealth;
use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Money\AccountCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        Livewire::withoutLazyLoading();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_reports_a_tidy_account_set(): void
    {
        Livewire::actingAs($this->user)->test(DataHealth::class)
            ->assertSee('Nothing to clean up');
    }

    #[Test]
    public function it_lists_the_proposed_merge_without_changing_anything(): void
    {
        [$first, $second] = $this->duplicates();

        Livewire::actingAs($this->user)->test(DataHealth::class)
            ->assertSee('Merge "Second" into "First"')
            ->assertSee('Ready');

        $this->assertFalse($second->fresh()->trashed());
        $this->assertFalse($first->fresh()->trashed());
    }

    #[Test]
    public function each_finding_shows_its_severity_impact_and_evidence(): void
    {
        $this->duplicates();

        Livewire::actingAs($this->user)->test(DataHealth::class)
            ->assertSee('High')
            ->assertSee('Impact:')
            ->assertSee('Evidence:');
    }

    #[Test]
    public function another_users_duplicates_never_appear(): void
    {
        $other = User::factory()->create();
        foreach (['Theirs one', 'Theirs two'] as $title) {
            EventObject::create(['user_id' => $other->id, 'concept' => 'account', 'type' => 'bank_account',
                'title' => $title, 'metadata' => ['account_id' => 'acc-9', 'currency' => 'GBP']]);
        }

        Livewire::actingAs($this->user)->test(DataHealth::class)
            ->assertDontSee('Theirs')
            ->assertSee('Nothing to clean up');
    }

    #[Test]
    public function applying_records_an_audit_entry_and_a_stale_plan_is_refused(): void
    {
        [, $second] = $this->duplicates();
        $plan = app(AccountCleanup::class)->plan((string) $this->user->id);

        $stale = ActionProgress::createProgress(userId: (string) $this->user->id, actionType: ApplyAccountCleanup::ACTION_TYPE, actionId: (string) Str::uuid(), step: 'queued', message: 'q', progress: 0);
        (new ApplyAccountCleanup((string) $this->user->id, 'not-the-reviewed-plan', (string) $stale->id))->handle(app(AccountCleanup::class));
        $this->assertFalse($second->fresh()->trashed());
        $this->assertArrayNotHasKey('money', $this->user->fresh()->settings ?? []);

        $progress = ActionProgress::createProgress(userId: (string) $this->user->id, actionType: ApplyAccountCleanup::ACTION_TYPE, actionId: (string) Str::uuid(), step: 'queued', message: 'q', progress: 0);
        (new ApplyAccountCleanup((string) $this->user->id, $plan['fingerprint'], (string) $progress->id))->handle(app(AccountCleanup::class));

        $audit = $this->user->fresh()->settings['money']['cleanup_audit'];
        $this->assertCount(1, $audit);
        $this->assertSame($plan['fingerprint'], $audit[0]['fingerprint']);
        $this->assertNotEmpty($audit[0]['applied']);

        Livewire::actingAs($this->user->fresh())->test(DataHealth::class)->assertSee('Recently applied');
    }

    #[Test]
    public function apply_queues_the_reviewed_plan_and_shows_progress(): void
    {
        Queue::fake();
        [, $second] = $this->duplicates();

        $component = Livewire::actingAs($this->user)->test(DataHealth::class);
        $fingerprint = $component->viewData('fingerprint');
        $component->call('apply', $fingerprint)->assertSee('Waiting to apply');

        Queue::assertPushed(ApplyAccountCleanup::class, fn ($job) => $job->fingerprint === $fingerprint && $job->userId === $this->user->id);
        $this->assertFalse($second->fresh()->trashed());
    }

    #[Test]
    public function a_second_apply_is_ignored_while_one_is_running(): void
    {
        Queue::fake();
        $this->duplicates();

        $component = Livewire::actingAs($this->user)->test(DataHealth::class);
        $fingerprint = $component->viewData('fingerprint');
        $component->call('apply', $fingerprint)->call('apply', $fingerprint);

        Queue::assertPushed(ApplyAccountCleanup::class, 1);
    }

    #[Test]
    public function a_category_name_can_be_saved_from_the_panel(): void
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo']);
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id, 'integration_group_id' => $group->id,
            'service' => 'monzo', 'instance_type' => 'accounts', 'configuration' => [],
        ]);
        $account = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_account', 'title' => 'Current']);
        $day = EventObject::create(['user_id' => $this->user->id, 'concept' => 'day', 'type' => 'day', 'title' => '2026-02-01', 'time' => '2026-02-01']);
        Event::create([
            'integration_id' => $integration->id, 'source_id' => 'tx', 'actor_id' => $account->id, 'target_id' => $day->id,
            'service' => 'monzo', 'domain' => 'money', 'action' => 'card_payment_to', 'time' => '2026-02-01 12:00:00',
            'value' => 100, 'value_multiplier' => 100, 'value_unit' => 'GBP', 'event_metadata' => ['category' => 'category_abc'],
        ]);

        Livewire::actingAs($this->user)->test(DataHealth::class)
            ->assertSee('Needs a name')
            ->set('categoryNames.category_abc', 'Lunch')
            ->call('saveCategoryName', 'category_abc')
            ->assertHasNoErrors();

        $this->assertSame('Lunch', $integration->fresh()->configuration['category_names']['category_abc']);
    }

    /**
     * @return array{0: EventObject, 1: EventObject}
     */
    private function duplicates(): array
    {
        $first = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'bank_account',
            'title' => 'First', 'metadata' => ['account_id' => 'acc-1', 'currency' => 'GBP']]);
        $first->forceFill(['created_at' => '2025-10-01'])->saveQuietly();
        $second = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'bank_account',
            'title' => 'Second', 'metadata' => ['account_id' => 'acc-1', 'currency' => 'GBP']]);

        return [$first, $second];
    }
}
