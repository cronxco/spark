<?php

namespace Tests\Feature;

use App\Jobs\OAuth\ManualLog\BoardGameGeekEnrichmentPull;
use App\Jobs\OAuth\ManualLog\VivinoEnrichmentPull;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualLogComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // played_board_game/drank_wine entries dispatch enrichment jobs
        // that make real outbound calls - fake them so these tests don't
        // depend on network access.
        Queue::fake([BoardGameGeekEnrichmentPull::class, VivinoEnrichmentPull::class]);
    }

    #[Test]
    public function logs_a_new_activity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('manual-log')
            ->set('actionType', 'drank_wine')
            ->set('title', 'Rioja Reserva')
            ->set('rating', 4)
            ->set('notes', 'Nice with dinner')
            ->call('save')
            ->assertHasNoErrors();

        $event = Event::whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
            ->where('service', 'manual_log')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('drank_wine', $event->action);
        $this->assertSame('Rioja Reserva', $event->target->title);
        $this->assertSame(4.0, (float) $event->formatted_value);
    }

    #[Test]
    public function requires_a_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('manual-log')
            ->set('actionType', 'drank_wine')
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);

        $this->assertSame(0, Event::where('service', 'manual_log')->count());
    }

    #[Test]
    public function rejects_a_rating_above_five(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('manual-log')
            ->set('actionType', 'drank_wine')
            ->set('title', 'Malbec')
            ->set('rating', 7)
            ->call('save')
            ->assertHasErrors(['rating' => 'max']);
    }

    #[Test]
    public function reuses_the_same_integration_across_entries(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('manual-log')
            ->set('actionType', 'drank_wine')
            ->set('title', 'Malbec')
            ->call('save');

        Volt::test('manual-log')
            ->set('actionType', 'played_board_game')
            ->set('title', 'Catan')
            ->call('save');

        $this->assertSame(
            1,
            Integration::where('user_id', $user->id)->where('service', 'manual_log')->count()
        );
        $this->assertSame(
            2,
            Event::where('service', 'manual_log')->count()
        );
    }

    #[Test]
    public function clears_the_form_after_saving(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('manual-log')
            ->set('actionType', 'drank_wine')
            ->set('title', 'Malbec')
            ->set('rating', 3)
            ->set('notes', 'Fine')
            ->call('save')
            ->assertSet('title', '')
            ->assertSet('rating', null)
            ->assertSet('notes', '');
    }
}
