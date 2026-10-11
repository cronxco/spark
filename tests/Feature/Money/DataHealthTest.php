<?php

namespace Tests\Feature\Money;

use App\Jobs\Money\ApplyAccountCleanup;
use App\Livewire\Money\DataHealth;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            ->assertSee('Everything looks tidy');
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
