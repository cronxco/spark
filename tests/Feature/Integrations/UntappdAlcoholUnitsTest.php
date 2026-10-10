<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Untappd\AlcoholUnits;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UntappdAlcoholUnitsTest extends TestCase
{
    #[Test]
    public function estimates_uk_units_from_abv_and_serving_style(): void
    {
        $this->assertSame(2.8, AlcoholUnits::estimate(5.0, 'Draft')['alcohol_units']);
        $this->assertSame(2.6, AlcoholUnits::estimate('6', 'Can')['alcohol_units']);

        $unknown = AlcoholUnits::estimate(5, null);
        $this->assertSame(2.2, $unknown['alcohol_units']);
        $this->assertTrue($unknown['alcohol_units_basis']['serving_ml_assumed']);

        $this->assertNull(AlcoholUnits::estimate(null, 'Can'));
        $this->assertNull(AlcoholUnits::estimate(0, 'Can'));
    }

    #[Test]
    public function stores_units_on_a_check_in_once_the_beer_abv_is_known(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['service' => 'untappd']);
        $beer = EventObject::withoutEvents(fn () => EventObject::factory()->create([
            'user_id' => $integration->user_id, 'concept' => 'drink', 'type' => 'beer', 'metadata' => [],
        ]));
        $event = Event::withoutEvents(fn () => Event::factory()->create([
            'integration_id' => $integration->id, 'service' => 'untappd', 'target_id' => $beer->id,
            'event_metadata' => ['serving_style' => 'Bottle'],
        ]));

        AlcoholUnits::applyTo($event->fresh());
        $this->assertArrayNotHasKey('alcohol_units', $event->fresh()->event_metadata);

        $beer->update(['metadata' => ['abv' => 7.5]]);
        AlcoholUnits::applyTo($event->fresh());
        $this->assertSame(2.5, $event->fresh()->event_metadata['alcohol_units']);
        $this->assertSame('Bottle', $event->fresh()->event_metadata['serving_style']);
    }
}
