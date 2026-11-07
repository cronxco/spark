<?php

namespace Tests\Feature\Flint;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The web digest card names the digest and lists every story with its
 * sources, or every pick with its read time (design language §7.3).
 */
class DigestEventCardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function news_roundup_card_lists_every_story_with_its_sources(): void
    {
        $this->actingAs(User::factory()->create());

        $view = $this->blade('<x-event-card :card="$card" />', ['card' => $this->card([
            'name' => 'News roundup',
            'facts' => ['3 stories', '4 sources'],
            'items' => [
                ['title' => 'Police protection scheme to cover more than 1,100 former MPs', 'sources' => ['BBC News', 'The Guardian'], 'read_minutes' => null],
                ['title' => 'Scottish data-centre plans face scrutiny', 'sources' => ['The Ferret'], 'read_minutes' => null],
                ['title' => 'Ministers weigh smart-glasses restrictions', 'sources' => ['The Verge'], 'read_minutes' => null],
            ],
        ])]);

        $view->assertSeeText('News roundup');
        $view->assertSeeText('3 stories');
        $view->assertSeeText('4 sources');
        $view->assertSeeText('Police protection scheme to cover more than 1,100 former MPs');
        $view->assertSeeText('BBC News, The Guardian');
        $view->assertSeeText('Ministers weigh smart-glasses restrictions');
        $view->assertDontSeeText('Had Digest');
    }

    #[Test]
    public function reading_list_card_shows_each_picks_read_time(): void
    {
        $this->actingAs(User::factory()->create());

        $view = $this->blade('<x-event-card :card="$card" />', ['card' => $this->card([
            'name' => 'Reading list',
            'facts' => ['1 pick'],
            'items' => [
                ['title' => 'The quiet death of the high street', 'sources' => [], 'read_minutes' => 12],
            ],
        ])]);

        $view->assertSeeText('Reading list');
        $view->assertSeeText('The quiet death of the high street');
        $view->assertSeeText('12 min read');
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function card(array $details): array
    {
        return [
            'kind' => 'flint.had_summary',
            'layout' => 'digest',
            'event' => [
                'id' => '9dd7d1ad-1e42-42ca-82c5-3e62222e5da9',
                'time' => '2026-11-07T00:00:00+00:00',
                'service' => 'flint',
                'domain' => 'knowledge',
                'action' => 'had_summary',
                'value' => 4,
                'unit' => null,
                'display_value' => '4',
                'display_name' => 'Had Digest',
            ],
            'context' => null,
            'at_digest' => null,
            'details' => $details,
            'answer' => null,
        ];
    }
}
