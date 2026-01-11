<?php

namespace Tests\Unit\Integrations\Oyster;

use App\Integrations\Oyster\OysterTransportModeDetector;
use Tests\TestCase;

class OysterTransportModeDetectorTest extends TestCase
{
    private OysterTransportModeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new OysterTransportModeDetector;
    }

    /** @test */
    public function it_detects_elizabeth_line()
    {
        $mode = $this->detector->detectMode('Paddington [Elizabeth line] to Canary Wharf [Elizabeth line]');
        $this->assertEquals(OysterTransportModeDetector::MODE_ELIZABETH, $mode);

        $mode = $this->detector->detectMode('Heathrow Terminal 5 [Elizabeth line] to East Croydon [National Rail]');
        $this->assertEquals(OysterTransportModeDetector::MODE_ELIZABETH, $mode);
    }

    /** @test */
    public function it_detects_london_overground()
    {
        $mode = $this->detector->detectMode('West Croydon [London Overground/National Rail] to London City Airport DLR');
        $this->assertEquals(OysterTransportModeDetector::MODE_OVERGROUND, $mode);

        $mode = $this->detector->detectMode('West Croydon [London Overground/National Rail] to Blackheath [National Rail]');
        $this->assertEquals(OysterTransportModeDetector::MODE_OVERGROUND, $mode);
    }

    /** @test */
    public function it_detects_national_rail()
    {
        $mode = $this->detector->detectMode('Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]');
        $this->assertEquals(OysterTransportModeDetector::MODE_NATIONAL_RAIL, $mode);

        $mode = $this->detector->detectMode('East Croydon [National Rail] to Victoria (platforms 9-19) [National Rail]');
        $this->assertEquals(OysterTransportModeDetector::MODE_NATIONAL_RAIL, $mode);
    }

    /** @test */
    public function it_detects_dlr()
    {
        $mode = $this->detector->detectMode('Greenwich [DLR/National Rail] to East Croydon [National Rail]');
        $this->assertEquals(OysterTransportModeDetector::MODE_DLR, $mode);

        $mode = $this->detector->detectMode('London City Airport DLR to East Croydon [National Rail]');
        $this->assertEquals(OysterTransportModeDetector::MODE_DLR, $mode);
    }

    /** @test */
    public function it_detects_tram()
    {
        $mode = $this->detector->detectMode('Entered Wandle Park tram stop');
        $this->assertEquals(OysterTransportModeDetector::MODE_TRAM, $mode);

        $mode = $this->detector->detectMode('Entered East Croydon tram stop');
        $this->assertEquals(OysterTransportModeDetector::MODE_TRAM, $mode);

        $mode = $this->detector->detectMode('Entered Wimbledon tram stop');
        $this->assertEquals(OysterTransportModeDetector::MODE_TRAM, $mode);
    }

    /** @test */
    public function it_detects_london_underground_as_default()
    {
        // Without mode annotations, station-to-station journeys default to tube
        $mode = $this->detector->detectMode('Westminster to Mansion House');
        $this->assertEquals(OysterTransportModeDetector::MODE_TUBE, $mode);

        $mode = $this->detector->detectMode('Baker Street to Westminster');
        $this->assertEquals(OysterTransportModeDetector::MODE_TUBE, $mode);

        $mode = $this->detector->detectMode('Earls Court to Wimbledon');
        $this->assertEquals(OysterTransportModeDetector::MODE_TUBE, $mode);
    }

    /** @test */
    public function it_parses_tram_entry()
    {
        $result = $this->detector->parseStations('Entered East Croydon tram stop');

        $this->assertEquals('East Croydon', $result['origin']);
        $this->assertNull($result['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_TRAM, $result['mode']);
    }

    /** @test */
    public function it_parses_national_rail_journey()
    {
        $result = $this->detector->parseStations('Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]');

        $this->assertEquals('Victoria', $result['origin']);
        $this->assertEquals('East Croydon', $result['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_NATIONAL_RAIL, $result['mode']);
    }

    /** @test */
    public function it_parses_tube_journey()
    {
        $result = $this->detector->parseStations('Westminster to Mansion House');

        $this->assertEquals('Westminster', $result['origin']);
        $this->assertEquals('Mansion House', $result['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_TUBE, $result['mode']);
    }

    /** @test */
    public function it_parses_mixed_mode_journey()
    {
        $result = $this->detector->parseStations('Farringdon to East Croydon [National Rail]');

        $this->assertEquals('Farringdon', $result['origin']);
        $this->assertEquals('East Croydon', $result['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_NATIONAL_RAIL, $result['mode']);
    }

    /** @test */
    public function it_identifies_non_journey_entries()
    {
        $this->assertTrue($this->detector->isNonJourney('Topped-up on touch in, Victoria (platforms 9-19) [National Rail]'));
        $this->assertTrue($this->detector->isNonJourney('Season ticket added on touch in, West Croydon [London Overground/National Rail]'));
        $this->assertTrue($this->detector->isNonJourney('Auto top-up activated'));

        $this->assertFalse($this->detector->isNonJourney('Victoria to East Croydon'));
        $this->assertFalse($this->detector->isNonJourney('Entered Wandle Park tram stop'));
    }

    /** @test */
    public function it_identifies_non_journey_types()
    {
        $this->assertEquals('topped_up_balance', $this->detector->getNonJourneyType('Topped-up on touch in, Victoria'));
        $this->assertEquals('added_season_ticket', $this->detector->getNonJourneyType('Season ticket added on touch in, West Croydon'));
        $this->assertNull($this->detector->getNonJourneyType('Victoria to East Croydon'));
    }

    /** @test */
    public function it_provides_display_names_for_modes()
    {
        $this->assertEquals('London Underground', OysterTransportModeDetector::getDisplayName(OysterTransportModeDetector::MODE_TUBE));
        $this->assertEquals('DLR', OysterTransportModeDetector::getDisplayName(OysterTransportModeDetector::MODE_DLR));
        $this->assertEquals('London Overground', OysterTransportModeDetector::getDisplayName(OysterTransportModeDetector::MODE_OVERGROUND));
        $this->assertEquals('Elizabeth line', OysterTransportModeDetector::getDisplayName(OysterTransportModeDetector::MODE_ELIZABETH));
        $this->assertEquals('Tram', OysterTransportModeDetector::getDisplayName(OysterTransportModeDetector::MODE_TRAM));
        $this->assertEquals('National Rail', OysterTransportModeDetector::getDisplayName(OysterTransportModeDetector::MODE_NATIONAL_RAIL));
    }
}
