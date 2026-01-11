<?php

namespace Tests\Unit\Integrations\Oyster;

use App\Integrations\Oyster\OysterCsvParser;
use App\Integrations\Oyster\OysterTransportModeDetector;
use Tests\TestCase;

class OysterCsvParserTest extends TestCase
{
    private OysterCsvParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new OysterCsvParser;
    }

    /** @test */
    public function it_parses_basic_csv_with_journeys()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,17:47,18:14,"Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]",.00,,8.41,""
28-Nov-2025,07:44,,"Entered Wandle Park tram stop",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $this->assertCount(2, $result['journeys']);
        $this->assertCount(0, $result['non_journeys']);
    }

    /** @test */
    public function it_parses_national_rail_journey_correctly()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,17:47,18:14,"Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]",2.80,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $this->assertCount(1, $result['journeys']);

        $journey = $result['journeys'][0];
        $this->assertTrue($journey['is_journey']);
        $this->assertEquals('28-Nov-2025', $journey['date']);
        $this->assertEquals('17:47', $journey['start_time']);
        $this->assertEquals('18:14', $journey['end_time']);
        $this->assertEquals('Victoria', $journey['origin']);
        $this->assertEquals('East Croydon', $journey['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_NATIONAL_RAIL, $journey['transport_mode']);
        $this->assertEquals(2.80, $journey['charge']);
        $this->assertEquals(8.41, $journey['balance']);
    }

    /** @test */
    public function it_parses_tram_entry_correctly()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,07:44,,"Entered Wandle Park tram stop",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $this->assertCount(1, $result['journeys']);

        $journey = $result['journeys'][0];
        $this->assertTrue($journey['is_journey']);
        $this->assertEquals('Wandle Park', $journey['origin']);
        $this->assertNull($journey['destination']);
        $this->assertNull($journey['end_time']);
        $this->assertEquals(OysterTransportModeDetector::MODE_TRAM, $journey['transport_mode']);
    }

    /** @test */
    public function it_parses_tube_journey_correctly()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
27-Aug-2025,18:01,18:10,"Westminster to Mansion House",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $journey = $result['journeys'][0];
        $this->assertEquals('Westminster', $journey['origin']);
        $this->assertEquals('Mansion House', $journey['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_TUBE, $journey['transport_mode']);
    }

    /** @test */
    public function it_parses_top_up_entries()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
18-Nov-2024,08:03,,"Topped-up on touch in, Victoria (platforms 9-19) [National Rail]",,15.00,19.11,""
CSV;

        $result = $this->parser->parse($csv);

        $this->assertCount(0, $result['journeys']);
        $this->assertCount(1, $result['non_journeys']);

        $topUp = $result['non_journeys'][0];
        $this->assertFalse($topUp['is_journey']);
        $this->assertEquals('topped_up_balance', $topUp['action_type']);
        $this->assertEquals(15.00, $topUp['credit']);
        $this->assertEquals(19.11, $topUp['balance']);
        $this->assertEquals('Victoria', $topUp['station']);
    }

    /** @test */
    public function it_parses_season_ticket_entries()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
13-Dec-2025,12:08,,"Season ticket added on touch in, West Croydon [London Overground/National Rail]",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $this->assertCount(0, $result['journeys']);
        $this->assertCount(1, $result['non_journeys']);

        $seasonTicket = $result['non_journeys'][0];
        $this->assertFalse($seasonTicket['is_journey']);
        $this->assertEquals('added_season_ticket', $seasonTicket['action_type']);
        $this->assertEquals('West Croydon', $seasonTicket['station']);
    }

    /** @test */
    public function it_parses_dlr_journeys()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
30-Aug-2025,15:25,15:59,"Greenwich [DLR/National Rail] to East Croydon [National Rail]",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $journey = $result['journeys'][0];
        $this->assertEquals('Greenwich', $journey['origin']);
        $this->assertEquals('East Croydon', $journey['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_DLR, $journey['transport_mode']);
    }

    /** @test */
    public function it_parses_elizabeth_line_journeys()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
19-Nov-2024,21:01,22:27,"Heathrow Terminal 5 [Elizabeth line] to East Croydon [National Rail]",1.80,,17.31,"You have been charged for travelling in zones not covered by your Travelcard."
CSV;

        $result = $this->parser->parse($csv);

        $journey = $result['journeys'][0];
        $this->assertEquals('Heathrow Terminal 5', $journey['origin']);
        $this->assertEquals('East Croydon', $journey['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_ELIZABETH, $journey['transport_mode']);
        $this->assertEquals(1.80, $journey['charge']);
        $this->assertStringContainsString('zones not covered', $journey['note']);
    }

    /** @test */
    public function it_parses_overground_journeys()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
22-Aug-2025,07:03,08:23,"West Croydon [London Overground/National Rail] to London City Airport DLR",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $journey = $result['journeys'][0];
        $this->assertEquals('West Croydon', $journey['origin']);
        $this->assertEquals('London City Airport', $journey['destination']);
        $this->assertEquals(OysterTransportModeDetector::MODE_OVERGROUND, $journey['transport_mode']);
    }

    /** @test */
    public function it_handles_zero_charges_for_season_ticket_journeys()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,17:47,18:14,"Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $journey = $result['journeys'][0];
        // .00 should be parsed as null (no charge for season ticket holders)
        $this->assertNull($journey['charge']);
    }

    /** @test */
    public function it_creates_valid_datetime_objects()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,17:47,18:14,"Victoria to East Croydon",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $journey = $result['journeys'][0];

        $this->assertNotNull($journey['start_datetime']);
        $this->assertNotNull($journey['end_datetime']);

        $this->assertEquals('2025-11-28', $journey['start_datetime']->format('Y-m-d'));
        $this->assertEquals('17:47', $journey['start_datetime']->format('H:i'));
        $this->assertEquals('18:14', $journey['end_datetime']->format('H:i'));
    }

    /** @test */
    public function it_handles_empty_csv()
    {
        $csv = '';

        $result = $this->parser->parse($csv);

        $this->assertCount(0, $result['journeys']);
        $this->assertCount(0, $result['non_journeys']);
    }

    /** @test */
    public function it_parses_multiple_journeys_in_correct_order()
    {
        $csv = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,18:14,,"Entered East Croydon tram stop",.00,,8.41,""
28-Nov-2025,17:47,18:14,"Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]",.00,,8.41,""
28-Nov-2025,07:59,08:24,"East Croydon [National Rail] to Victoria (platforms 9-19) [National Rail]",.00,,8.41,""
28-Nov-2025,07:44,,"Entered Wandle Park tram stop",.00,,8.41,""
CSV;

        $result = $this->parser->parse($csv);

        $this->assertCount(4, $result['journeys']);

        // First entry should be tram at East Croydon
        $this->assertEquals('East Croydon', $result['journeys'][0]['origin']);
        $this->assertEquals(OysterTransportModeDetector::MODE_TRAM, $result['journeys'][0]['transport_mode']);

        // Last entry should be tram at Wandle Park
        $this->assertEquals('Wandle Park', $result['journeys'][3]['origin']);
    }
}
