<?php

namespace Tests\Unit\Services\ManualLog;

use App\Services\ManualLog\ManualLogLookup;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualLogLookupTest extends TestCase
{
    #[Test]
    public function a_chosen_vintage_picks_that_vintage_not_the_first_of_the_wine(): void
    {
        $lookup = app(ManualLogLookup::class);

        $picked = $lookup->pick($this->vintages(), 'Monthelie', 'https://www.vivino.com/en/x/w/1?year=2024&price_id=9');

        $this->assertSame('https://www.vivino.com/en/x/w/1?year=2024', $picked['url']);
    }

    #[Test]
    public function a_different_wine_page_is_not_a_match(): void
    {
        $lookup = app(ManualLogLookup::class);

        $picked = $lookup->pick($this->vintages(), 'Monthelie 2023', 'https://www.vivino.com/en/y/w/2?year=2023');

        $this->assertSame('https://www.vivino.com/en/x/w/1?year=2023', $picked['url']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vintages(): array
    {
        return [
            ['title' => 'Les Toisieres Monthelie Rouge 2023', 'url' => 'https://www.vivino.com/en/x/w/1?year=2023'],
            ['title' => 'Les Toisieres Monthelie Rouge 2024', 'url' => 'https://www.vivino.com/en/x/w/1?year=2024'],
        ];
    }
}
