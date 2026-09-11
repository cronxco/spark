<?php

namespace Tests\Unit\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\User;
use App\Services\FlintDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    private function firstBlock(array $result): Block
    {
        return Event::findOrFail($result['event_id'])->blocks->first();
    }
}
