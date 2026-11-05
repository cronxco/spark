<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Data\Oura\OuraHeartrateData;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class OuraPaginationTest extends TestCase
{
    #[Test]
    public function all_pages_are_merged_without_losing_window_parameters(): void
    {
        Http::fakeSequence()
            ->push(['data' => [['bpm' => 60]], 'next_token' => 'second'])
            ->push(['data' => [['bpm' => 80]], 'next_token' => null]);

        $result = (new OuraPlugin)->getJson('/usercollection/heartrate', $this->integration(), ['start_datetime' => '2026-10-07T00:00:00Z']);

        $this->assertCount(2, $result['data']);
        $this->assertNull($result['next_token']);
        Http::assertSent(fn ($request) => ($request['next_token'] ?? null) === 'second' && $request['start_datetime'] === '2026-10-07T00:00:00Z');
        Http::assertSentCount(2);
    }

    #[Test]
    public function failed_later_page_fails_the_fetch_instead_of_returning_partial_data(): void
    {
        Http::fakeSequence()
            ->push(['data' => [['bpm' => 60]], 'next_token' => 'second'])
            ->push([], 429);

        $this->expectException(RequestException::class);
        (new OuraPlugin)->getJson('/usercollection/heartrate', $this->integration());
    }

    #[Test]
    public function repeated_pagination_tokens_fail_instead_of_looping_forever(): void
    {
        Http::fakeSequence()
            ->push(['data' => [], 'next_token' => 'same'])
            ->push(['data' => [], 'next_token' => 'same']);

        $this->expectExceptionMessage('repeated pagination token');
        (new OuraPlugin)->getJson('/usercollection/heartrate', $this->integration());
    }

    #[Test]
    public function missing_collection_data_on_later_page_fails(): void
    {
        Http::fakeSequence()
            ->push(['data' => [['bpm' => 60]], 'next_token' => 'second'])
            ->push(['next_token' => null]);

        $this->expectExceptionMessage('invalid collection page');
        (new OuraPlugin)->getJson('/usercollection/heartrate', $this->integration());
    }

    #[Test]
    public function current_day_aggregate_is_explicitly_provisional(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10T11:38:00Z'));
        try {
            $job = new OuraHeartrateData($this->integration(), []);
            $method = new ReflectionMethod($job, 'createHeartrateEvent');
            $points = collect([['bpm' => 60], ['bpm' => 80]]);
            $today = $method->invoke($job, '2026-10-10', $points, new OuraPlugin);
            $yesterday = $method->invoke($job, '2026-10-09', $points, new OuraPlugin);

            $this->assertTrue($today['event_metadata']['is_provisional']);
            $this->assertFalse($yesterday['event_metadata']['is_provisional']);
            $this->assertSame('UTC', $today['event_metadata']['aggregation_timezone']);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function personal_info_without_a_collection_remains_unchanged(): void
    {
        Http::fake(['*' => Http::response(['email' => 'person@example.test'])]);

        $this->assertSame(['email' => 'person@example.test'], (new OuraPlugin)->getJson('/usercollection/personal_info', $this->integration()));
    }

    #[Test]
    public function heart_rate_fetch_starts_at_midnight_instead_of_a_sliding_partial_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10T11:38:00Z'));
        try {
            $integration = $this->integration();
            $integration->configuration = [
                'oura_incremental_days' => 3,
                'oura_last_sweep_at' => '2026-10-10T10:00:00Z',
            ];
            $plugin = Mockery::mock(OuraPlugin::class)->makePartial();
            $plugin->shouldReceive('getJson')->once()->with('/usercollection/heartrate', $integration, [
                'start_datetime' => '2026-10-07T00:00:00+00:00',
                'end_datetime' => '2026-10-10T11:38:00+00:00',
            ])->andReturn(['data' => []]);

            $this->assertSame([], $plugin->pullHeartrateData($integration));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function integration(): Integration
    {
        $integration = new Integration(['id' => 'test', 'service' => 'oura']);
        $integration->setRelation('group', new IntegrationGroup(['access_token' => 'test-token']));

        return $integration;
    }
}
