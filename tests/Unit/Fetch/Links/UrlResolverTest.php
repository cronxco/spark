<?php

namespace Tests\Unit\Fetch\Links;

use App\Services\Fetch\Links\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\FrameworkTestCase;

class UrlResolverTest extends FrameworkTestCase
{
    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function links(): array
    {
        return [
            'absolute' => ['https://other.com/x', 'https://example.com/blog/', 'https://other.com/x'],
            'root relative' => ['/about', 'https://example.com/blog/post', 'https://example.com/about'],
            'relative to directory' => ['post-2', 'https://example.com/blog/', 'https://example.com/blog/post-2'],
            'relative to file' => ['post-2', 'https://example.com/blog/post-1', 'https://example.com/blog/post-2'],
            'parent directory' => ['../archive', 'https://example.com/blog/2026/', 'https://example.com/blog/archive'],
            'protocol relative' => ['//cdn.example.com/a', 'https://example.com/', 'https://cdn.example.com/a'],
            'entity encoded' => ['/search?a=1&amp;b=2', 'https://example.com/', 'https://example.com/search?a=1&b=2'],
            'mailto' => ['mailto:hi@example.com', 'https://example.com/', null],
            'javascript' => ['javascript:void(0)', 'https://example.com/', null],
            'empty' => ['  ', 'https://example.com/', null],
        ];
    }

    #[Test]
    #[DataProvider('links')]
    public function it_resolves_links(string $href, string $base, ?string $expected): void
    {
        $this->assertSame($expected, UrlResolver::resolve($href, $base));
    }

    #[Test]
    public function it_resolves_base_href_against_the_page(): void
    {
        $this->assertSame('https://example.com/news/', UrlResolver::documentBase('/news/', 'https://example.com/index.html'));
        $this->assertSame('https://example.com/index.html', UrlResolver::documentBase(null, 'https://example.com/index.html'));
    }
}
