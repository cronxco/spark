<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\Vivino\VivinoActivityData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VivinoActivityDataTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_HTML = <<<'HTML'
        <html><body>
            <div data-testid="activityFeedItem">
                <a data-testid="wineNameLink" href="https://www.vivino.com/wines/1234">
                    <span data-testid="wineName">Malbec Reserva 2021</span>
                </a>
                <span data-testid="wineryName">Bodega Test</span>
                <span data-testid="regionName">Mendoza</span>
                <span data-testid="ratingStars" aria-label="Rated 4.5 out of 5"></span>
            </div>
            <div data-testid="activityFeedItem">
                <a data-testid="wineNameLink" href="https://www.vivino.com/wines/5678">
                    <span data-testid="wineName">Rioja Crianza</span>
                </a>
            </div>
        </body></html>
        HTML;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'vivino',
            'auth_metadata' => [
                'vivino_profile_url' => 'https://www.vivino.com/users/testuser',
            ],
        ]);

        $this->integration = Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'vivino',
            'name' => 'Vivino',
            'instance_type' => 'activity',
            'configuration' => ['update_frequency_minutes' => 180],
        ]);
    }

    #[Test]
    public function creates_an_event_per_wine_with_a_details_block_when_data_is_available(): void
    {
        $job = new VivinoActivityData($this->integration, ['html' => self::FIXTURE_HTML]);
        $job->handle();

        $malbecEvent = Event::where('integration_id', $this->integration->id)
            ->whereHas('target', fn ($q) => $q->where('title', 'Malbec Reserva 2021'))
            ->with('blocks', 'target')
            ->first();

        $this->assertNotNull($malbecEvent);
        $this->assertSame('vivino', $malbecEvent->service);
        $this->assertSame('health', $malbecEvent->domain);
        $this->assertSame('drank_wine', $malbecEvent->action);
        $this->assertSame(4.5, (float) $malbecEvent->formatted_value);
        $this->assertSame('/5', $malbecEvent->value_unit);
        $this->assertSame('vivino_wine', $malbecEvent->target->type);
        $this->assertSame('Bodega Test', $malbecEvent->target->metadata['winery']);

        $detailsBlock = $malbecEvent->blocks->firstWhere('block_type', 'wine_details');
        $this->assertNotNull($detailsBlock);
        $this->assertSame('Mendoza', $detailsBlock->metadata['region']);
    }

    #[Test]
    public function creates_an_event_without_a_block_when_there_is_no_extra_detail(): void
    {
        $job = new VivinoActivityData($this->integration, ['html' => self::FIXTURE_HTML]);
        $job->handle();

        $riojaEvent = Event::where('integration_id', $this->integration->id)
            ->whereHas('target', fn ($q) => $q->where('title', 'Rioja Crianza'))
            ->with('blocks')
            ->first();

        $this->assertNotNull($riojaEvent);
        $this->assertNull($riojaEvent->value);
        $this->assertCount(0, $riojaEvent->blocks);
    }

    #[Test]
    public function does_not_duplicate_events_on_a_repeat_fetch_of_the_same_page(): void
    {
        (new VivinoActivityData($this->integration, ['html' => self::FIXTURE_HTML]))->handle();
        (new VivinoActivityData($this->integration, ['html' => self::FIXTURE_HTML]))->handle();

        $this->assertSame(
            2,
            Event::where('integration_id', $this->integration->id)->count()
        );
    }

    #[Test]
    public function does_nothing_for_empty_html(): void
    {
        $job = new VivinoActivityData($this->integration, ['html' => '']);
        $job->handle();

        $this->assertSame(0, Event::where('integration_id', $this->integration->id)->count());
    }
}
