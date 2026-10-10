<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Oura\OuraPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OuraPaginationTest extends TestCase
{
    private function integration(): Integration
    {
        $integration = new Integration(['id' => 'test', 'service' => 'oura']);
        $integration->setRelation('group', new IntegrationGroup(['access_token' => 'test-token']));

        return $integration;
    }

    #[Test]
    public function all_pages_are_merged_without_losing_window_parameters(): void
    {
        Http::fakeSequence()
            ->push(['data' => [['bpm' => 60]], 'next_token' => 'second'])
            ->push(['data' => [['bpm' => 80]], 'next_token' => null]);

        $result = (new OuraPlugin)->getJson('/usercollection/heartrate', $this->integration(), ['start_datetime' => '2026-10-07T00:00:00Z']);

        $this->assertCount(2, $result['data']);
        $this->assertNull($result['next_token']);
        Http::assertSent(fn ($request) => $request['next_token'] === 'second' && $request['start_datetime'] === '2026-10-07T00:00:00Z');
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
}
