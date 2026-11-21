<?php

namespace Tests\Unit\Services\Flint\Ask;

use App\Services\Flint\Ask\AskFlintSteps;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Flint's steps in the reader's words: what it looked at, what that covered,
 * what it found, and the records it found, never the raw call.
 */
class AskFlintStepsTest extends TestCase
{
    private const EVENT_ID = '5f0c1d2e-3a4b-4c5d-8e9f-0a1b2c3d4e5f';

    #[Test]
    public function a_search_says_what_it_searched_for(): void
    {
        $step = AskFlintSteps::started('call-1', 'search-events-tool', ['query' => 'flight', 'from_date' => '2026-11-22', 'to_date' => '2026-11-28']);

        $this->assertSame('Searched events for “flight”', $step['label']);
        $this->assertSame('Sun 22 Nov to Sat 28 Nov', $step['detail']);
        $this->assertSame('running', $step['status']);
        $this->assertSame('magnifying-glass', $step['glyph']);
    }

    #[Test]
    public function a_calendar_filter_reads_as_listing_the_calendar(): void
    {
        [$label] = AskFlintSteps::label('get-events-by-filter-tool', ['service' => 'calendar']);

        $this->assertSame('Listed your calendar', $label);
    }

    #[Test]
    public function a_finished_step_counts_its_results_and_names_the_first_records(): void
    {
        $result = json_encode([
            'events' => [
                ['id' => self::EVENT_ID, 'action' => 'had_event', 'target' => ['title' => 'QF10 to Perth']],
                ['id' => 'not-a-uuid', 'title' => 'Ignored'],
            ],
            'meta' => ['count' => 2],
        ]);

        $step = AskFlintSteps::finished(AskFlintSteps::started('call-1', 'search-events-tool', ['query' => 'flight']), $result, 2100);

        $this->assertSame('done', $step['status']);
        $this->assertSame('2 matches', $step['result']);
        $this->assertSame(2100, $step['duration_ms']);
        $this->assertSame([['kind' => 'event', 'id' => self::EVENT_ID, 'title' => 'QF10 to Perth']], $step['records']);
    }

    #[Test]
    public function an_empty_result_says_nothing_was_found(): void
    {
        $step = AskFlintSteps::finished(AskFlintSteps::started('call-1', 'get-events-by-filter-tool', []), json_encode(['events' => []]));

        $this->assertSame('Nothing found', $step['result']);
    }

    #[Test]
    public function a_failed_call_is_marked_failed_without_its_error(): void
    {
        $step = AskFlintSteps::finished(AskFlintSteps::started('call-1', 'get-day-summary-tool', ['date' => '2026-11-27']), 'Error: something broke inside');

        $this->assertSame('failed', $step['status']);
        $this->assertSame('Couldn’t read this', $step['result']);
        $this->assertSame([], $step['records']);
    }

    #[Test]
    public function stored_rounds_become_reasoning_and_tool_steps_in_order(): void
    {
        $steps = AskFlintSteps::fromStored([
            ['content' => '', 'reasoning' => 'Check the calendar for the week first.', 'tool_calls' => [
                ['id' => 'call-1', 'name' => 'get-day-summary-tool', 'arguments' => ['date' => '2026-11-27'], 'result' => '{"date":"2026-11-27"}'],
            ]],
            ['content' => 'A light start.', 'reasoning' => '', 'tool_calls' => []],
        ], ['call-1' => 1400]);

        $this->assertCount(2, $steps);
        $this->assertSame('reasoning', $steps[0]['type']);
        $this->assertSame('Check the calendar for the week first.', $steps[0]['detail']);
        $this->assertSame('Read the day', $steps[1]['label']);
        $this->assertSame('Fri 27 Nov', $steps[1]['detail']);
        $this->assertSame(1400, $steps[1]['duration_ms']);
        $this->assertSame('done', $steps[1]['status']);
    }

    #[Test]
    public function an_unknown_tool_gets_a_readable_name(): void
    {
        [$label, $glyph] = AskFlintSteps::label('get-weather-forecast-tool');

        $this->assertSame('Get weather forecast', $label);
        $this->assertSame('gear', $glyph);
    }
}
