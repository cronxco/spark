<?php

namespace Tests\Feature\Blocks;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every custom block layout renders through the shared shell, and none of
 * them brings back the chrome the design language removed (DESIGN_LANGUAGE.md
 * §7.1 and §12): per-card menus, footer badges, shadows, developer metadata
 * and hard-coded pound signs.
 */
class BlockCardLayoutsTest extends TestCase
{
    use RefreshDatabase;

    public static function layouts(): array
    {
        return collect(glob(dirname(__DIR__, 3).'/resources/views/blocks/types/*.blade.php'))
            ->map(fn (string $path) => basename($path, '.blade.php'))
            ->mapWithKeys(fn (string $type) => [$type => [$type]])
            ->all();
    }

    #[Test]
    #[DataProvider('layouts')]
    public function the_layout_renders_inside_the_shared_shell(string $blockType): void
    {
        $block = $this->makeBlock($blockType);

        $html = Blade::render('<x-block-card :block="$block" />', ['block' => $block]);

        $this->assertStringContainsString('<article', $html);
        $this->assertTrue(
            str_contains($html, route('blocks.show', $block)) || str_contains($html, 'href="https://example.com/article"'),
            'The card links to the block, or to its source for reading picks'
        );
        $this->assertStringNotContainsString('dropdown-content', $html);
        $this->assertStringNotContainsString('hover:shadow-lg', $html);
        $this->assertStringNotContainsString('animate-pulse', $html);
        $this->assertStringNotContainsString('bg-gradient', $html);
        $this->assertStringNotContainsString('gpt-test-model', $html);
        $this->assertStringNotContainsString('APPROVED', $html);
        $this->assertStringNotContainsString('Line 12', $html);
        $this->assertStringNotContainsString('£', $html, 'The block is in euros, so a pound sign is hard-coded');
    }

    #[Test]
    public function generated_text_is_marked_in_words(): void
    {
        $html = Blade::render('<x-block-card :block="$block" />', ['block' => $this->makeBlock('newsletter_tldr')]);

        $this->assertStringContainsString('Summary', $html);
        $this->assertStringNotContainsString('words', $html);
    }

    #[Test]
    public function the_generic_value_card_no_longer_lists_raw_metadata(): void
    {
        $html = Blade::render('<x-block-card :block="$block" />', ['block' => $this->makeBlock('some_unlisted_type')]);

        $this->assertStringContainsString('<article', $html);
        $this->assertStringNotContainsString('Internal key', $html);
    }

    private function makeBlock(string $blockType): Block
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'action' => 'payment_to',
        ]);

        return $event->createBlock([
            'block_type' => $blockType,
            'title' => 'A block title',
            'time' => now()->subHour(),
            'url' => 'https://example.com/article',
            'value' => 1250,
            'value_multiplier' => 100,
            'value_unit' => 'EUR',
            'metadata' => [
                'content' => "- First takeaway\n- Second takeaway",
                'model' => 'gpt-test-model',
                'word_count' => 120,
                'line_number' => 12,
                'internal_key' => 'Internal key',
                'checked' => true,
                'people' => [['name' => 'Ada', 'photo_count' => 3]],
                'photo_count' => 9,
                'thumbnail_urls' => ['https://example.com/1.jpg'],
                'genres' => ['jazz'],
                'followers' => 1200,
                'artists' => ['Someone'],
                'popularity' => 55,
                'exercise_name' => 'Bench press',
                'set_number' => 2,
                'reps' => 8,
                'rpe' => 7,
                'sort_code' => '00-00-00',
                'account_number' => '12345678',
                'EUR' => 12.5,
                'USD' => 13.6,
                'rate' => 1.088,
                'subtotal' => 1000,
                'unit_price' => 250,
                'quantity' => 2,
                'payment_method' => 'contactless',
                'card_last_4' => '4242',
                'time' => '2026-10-28 09:30:00',
                'news' => ['summary' => 'A story', 'key_points' => ['One']],
                'day_context' => ['calendar' => [['title' => 'Stand-up', 'start' => '2026-10-28T09:00:00Z']]],
                'question' => 'Why were you up so late?',
            ],
        ])->load('event');
    }
}
