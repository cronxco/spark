<?php

namespace Tests\Feature\Livewire;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdatesIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_invites_you_to_connect_when_there_are_no_integrations(): void
    {
        Volt::actingAs($this->user)
            ->test('updates.index')
            ->assertSee('No integrations yet')
            ->assertDontSee('Nothing matches');
    }

    #[Test]
    public function it_explains_an_empty_search_instead_of_claiming_nothing_is_connected(): void
    {
        $this->makeIntegration('oura', 'Sleep');

        Volt::actingAs($this->user)
            ->test('updates.index')
            ->set('search', 'no-such-integration')
            ->assertSee('Nothing matches')
            ->assertDontSee('No integrations yet')
            ->call('resetFilters')
            ->assertSee('Sleep');
    }

    #[Test]
    public function search_matches_the_plugin_display_name(): void
    {
        $this->makeIntegration('daily_checkin', 'Morning');
        $this->makeIntegration('oura', 'Sleep');

        Volt::actingAs($this->user)
            ->test('updates.index')
            ->set('search', 'check-in')
            ->assertSee('Daily Check-in')
            ->assertDontSee('Oura');
    }

    #[Test]
    public function it_reports_everything_up_to_date_when_nothing_is_due(): void
    {
        $this->makeIntegration('oura', 'Sleep', ['last_successful_update_at' => now()->subMinutes(5)]);

        Volt::actingAs($this->user)
            ->test('updates.index')
            ->assertSee('Everything is up to date')
            ->assertDontSee('Needs update');
    }

    #[Test]
    public function an_overdue_pull_integration_needs_attention_and_its_group_opens_by_default(): void
    {
        $this->makeIntegration('oura', 'Sleep', [
            'last_successful_update_at' => now()->subHours(3),
            'configuration' => ['update_frequency_minutes' => 60],
        ]);

        Volt::actingAs($this->user)
            ->test('updates.index')
            ->assertSee('1 integration needs attention')
            ->assertSet('expanded.oura', true)
            ->assertSee('Needs update')
            ->assertSee('Update now');
    }

    #[Test]
    public function a_quiet_manual_integration_is_not_reported_as_needing_an_update(): void
    {
        $manual = $this->makeIntegration('daily_checkin', 'Morning');
        Event::factory()->create(['integration_id' => $manual->id, 'time' => now()->subDays(45)]);

        Volt::actingAs($this->user)
            ->test('updates.index')
            ->assertSee('Everything is up to date')
            ->assertSee('1 quiet')
            ->assertDontSee('need update')
            ->assertSet('expanded.daily_checkin', false);
    }

    #[Test]
    public function the_sweep_is_summarised_once_per_plugin(): void
    {
        $this->makeIntegration('oura', 'Sleep', ['configuration' => ['oura_last_sweep_at' => now()->subHours(8)->toIso8601String()]]);
        $this->makeIntegration('oura', 'Stress');

        Volt::actingAs($this->user)
            ->test('updates.index')
            ->assertSeeInOrder(['Oura', 'Daily sweep', 'last 30 days', '8 hours ago']);
    }

    #[Test]
    public function it_pauses_and_resumes_an_integration(): void
    {
        $integration = $this->makeIntegration('oura', 'Sleep', ['last_successful_update_at' => now()->subMinutes(5)]);

        $component = Volt::actingAs($this->user)
            ->test('updates.index')
            ->call('toggle', 'oura')
            ->call('togglePause', $integration->id)
            ->assertSee('Resume');

        $this->assertTrue($integration->fresh()->isPaused());

        $component->call('togglePause', $integration->id)->assertSee('Pause');

        $this->assertFalse($integration->fresh()->isPaused());
    }

    #[Test]
    public function it_cannot_pause_or_trigger_someone_elses_integration(): void
    {
        Queue::fake();
        $other = User::factory()->create();
        $theirs = Integration::factory()->create([
            'user_id' => $other->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $other->id, 'service' => 'oura'])->id,
            'service' => 'oura',
        ]);

        Volt::actingAs($this->user)
            ->test('updates.index')
            ->call('togglePause', $theirs->id)
            ->call('triggerUpdate', $theirs->id);

        $this->assertFalse($theirs->fresh()->isPaused());
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeIntegration(string $service, string $name, array $attributes = []): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => $service]);

        return Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => $service,
            'name' => $name,
            ...$attributes,
        ]);
    }
}
