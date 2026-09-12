<?php

namespace Tests\Unit\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\User;
use App\Services\Flint\FlintRunToken;
use App\Services\FlintDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintDigestServiceTest extends TestCase
{
    use RefreshDatabase;

    private FlintDigestService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FlintDigestService::class);
        $this->user = User::factory()->create();
    }

    /**
     * The same news-story block was written as `flint_story`, then
     * `flint_news_roundup_story`, then `flint_news` over eight days, because
     * `starts_with:flint_` accepted all three. Unregistered types render as an
     * unlabelled grey card everywhere, silently.
     */
    #[Test]
    public function rejects_a_block_type_the_plugin_does_not_register(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->user, [
            'title' => 'News roundup — Tuesday',
            'period' => 'morning',
            'blocks' => [
                ['block_type' => 'flint_story', 'title' => 'A story', 'content' => 'Something happened.'],
            ],
        ]);
    }

    #[Test]
    public function accepts_every_registered_flint_block_type(): void
    {
        $result = $this->service->create($this->user, [
            'title' => 'Reading list — Tuesday',
            'period' => 'evening',
            'blocks' => [
                ['block_type' => 'flint_news', 'title' => 'A story', 'content' => 'Something happened.'],
                ['block_type' => 'flint_reading_pick', 'title' => 'A piece', 'content' => 'Why tonight.', 'url' => 'https://example.com/a', 'minutes' => 12],
                ['block_type' => 'flint_reading_drop', 'title' => 'An old piece', 'content' => 'Aged out.', 'url' => 'https://example.com/b'],
                ['block_type' => 'flint_insight', 'title' => 'An insight', 'content' => 'Noted.'],
                ['block_type' => 'flint_editorial_note', 'title' => 'Run notes', 'content' => 'Ran fine.'],
            ],
        ]);

        $this->assertSame(5, $result['block_count']);
    }

    /**
     * A reading pick's link and length belong on the block's own columns, so a
     * client can render and sort them without unpacking JSON — and so the app
     * stops recovering them from prose with a regex.
     */
    #[Test]
    public function stores_a_reading_pick_url_and_minutes_on_the_block(): void
    {
        $result = $this->service->create($this->user, [
            'title' => 'Reading list — Tuesday',
            'period' => 'evening',
            'blocks' => [
                [
                    'block_type' => 'flint_reading_pick',
                    'title' => 'Reversing UK mobile rail tickets',
                    'content' => 'You are on the Paddington leg today.',
                    'url' => 'https://eta.st/2023/01/31/rail-tickets.html',
                    'minutes' => 15,
                ],
            ],
        ]);

        $block = $this->firstBlock($result);

        $this->assertSame('https://eta.st/2023/01/31/rail-tickets.html', $block->url);
        $this->assertSame(15, (int) $block->formatted_value);
        $this->assertSame('minutes', $block->value_unit);
    }

    /**
     * The client was deciding a digest's layout by looking for "news" in its
     * title. The server already knows which routine wrote it, from a verified
     * run token.
     */
    #[Test]
    public function records_the_digest_kind_from_the_routine(): void
    {
        $result = $this->service->create($this->user, [
            'title' => 'Anything At All',
            'period' => 'evening',
            'run_token' => $this->runTokenFor('reading_list', 'evening'),
            'blocks' => [],
        ]);

        $event = Event::find($result['event_id']);

        $this->assertSame('reading_list', $event->event_metadata['kind']);
    }

    #[Test]
    public function records_no_kind_for_a_digest_written_without_a_run_token(): void
    {
        $result = $this->service->create($this->user, [
            'title' => 'Morning Digest',
            'period' => 'morning',
            'blocks' => [],
        ]);

        $event = Event::find($result['event_id']);

        $this->assertArrayNotHasKey('kind', $event->event_metadata);
    }

    #[Test]
    public function stores_a_day_context_block_with_calendar_birthdays_and_weather(): void
    {
        $result = $this->service->create($this->user, [
            'title' => 'Morning Digest',
            'period' => 'morning',
            'blocks' => [
                [
                    'block_type' => 'flint_day_context',
                    'title' => 'Today at a glance',
                    'day_context' => [
                        'calendar' => [
                            ['title' => 'Will · Office', 'all_day' => false, 'start' => '2026-05-10T09:00:00+01:00', 'person' => 'will'],
                            ['title' => 'Dan · Office', 'all_day' => false, 'start' => '2026-05-10T09:00:00+01:00', 'person' => 'dan'],
                        ],
                        'birthdays' => [
                            ['title' => "Daniel's birthday"],
                        ],
                        'weather' => ['location' => 'London', 'condition' => 'Overcast', 'temp_high_c' => 20, 'rain_probability_pct' => 38],
                    ],
                ],
            ],
        ]);

        $block = $this->firstBlock($result);

        $this->assertSame('flint_day_context', $block->block_type);
        $this->assertSame('will', $block->metadata['day_context']['calendar'][0]['person']);
        $this->assertSame('dan', $block->metadata['day_context']['calendar'][1]['person']);
        $this->assertSame("Daniel's birthday", $block->metadata['day_context']['birthdays'][0]['title']);
        $this->assertArrayNotHasKey('person', $block->metadata['day_context']['birthdays'][0]);
        $this->assertSame('Overcast', $block->metadata['day_context']['weather']['condition']);
        $this->assertArrayNotHasKey('content', $block->metadata);
    }

    #[Test]
    public function defaults_a_missing_person_to_will_rather_than_failing_the_whole_write(): void
    {
        $result = $this->service->create($this->user, [
            'title' => 'Morning Digest',
            'blocks' => [
                [
                    'block_type' => 'flint_day_context',
                    'title' => 'Today at a glance',
                    'day_context' => [
                        'calendar' => [
                            ['title' => 'Team standup', 'all_day' => false],
                        ],
                    ],
                ],
            ],
        ]);

        $block = $this->firstBlock($result);

        $this->assertSame('will', $block->metadata['day_context']['calendar'][0]['person']);
    }

    #[Test]
    public function rejects_an_invalid_person_value(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->user, [
            'title' => 'Morning Digest',
            'blocks' => [
                [
                    'block_type' => 'flint_day_context',
                    'title' => 'Today at a glance',
                    'day_context' => [
                        'calendar' => [
                            ['title' => 'Team standup', 'person' => 'someone_else'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function rejects_more_than_twenty_calendar_entries(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->user, [
            'title' => 'Morning Digest',
            'blocks' => [
                [
                    'block_type' => 'flint_day_context',
                    'title' => 'Today at a glance',
                    'day_context' => [
                        'calendar' => array_fill(0, 21, ['title' => 'Event', 'person' => 'will']),
                    ],
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $result */
    /**
     * Without a run token the source id was a fresh uuid, so a conversational
     * digest retried after an unknown outcome wrote a second copy. The natural
     * key is the same one a person would use: this user's digest for this date,
     * period and title.
     */
    #[Test]
    public function a_tokenless_digest_is_idempotent_on_date_period_and_title(): void
    {
        $payload = [
            'title' => 'Morning Digest — Sat 12 Sep',
            'period' => 'morning',
            'date' => '2026-09-12',
            'summary' => 'Good Saturday morning.',
        ];

        $first = $this->service->create($this->user, $payload);
        $second = $this->service->create($this->user, $payload);

        $this->assertFalse($first['deduplicated']);
        $this->assertTrue($second['deduplicated']);
        $this->assertSame($first['event_id'], $second['event_id']);
        $this->assertSame(1, Event::where('service', 'flint')->where('action', 'had_summary')->count());
    }

    #[Test]
    public function a_different_title_on_the_same_day_is_a_different_digest(): void
    {
        $base = ['period' => 'morning', 'date' => '2026-09-12'];

        $briefing = $this->service->create($this->user, $base + ['title' => 'Morning Digest — Sat 12 Sep']);
        $roundup = $this->service->create($this->user, $base + ['title' => 'News roundup — Saturday']);

        $this->assertNotSame($briefing['event_id'], $roundup['event_id']);
        $this->assertFalse($roundup['deduplicated']);
    }

    private function runTokenFor(string $routine, string $period): string
    {
        return app(FlintRunToken::class)->issue([
            'user_id' => (string) $this->user->id,
            'local_date' => now($this->user->getTimezone())->toDateString(),
            'period' => $period,
            'run_uuid' => (string) Str::uuid(),
            'routine' => $routine,
        ]);
    }

    private function firstBlock(array $result): Block
    {
        return Event::findOrFail($result['event_id'])->blocks->first();
    }
}
