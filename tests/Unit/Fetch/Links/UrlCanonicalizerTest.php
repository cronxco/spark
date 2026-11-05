<?php

namespace Tests\Unit\Fetch\Links;

use App\Services\Fetch\Links\UrlCanonicalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\FrameworkTestCase;

class UrlCanonicalizerTest extends FrameworkTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function urls(): array
    {
        return [
            'lowercases scheme and host' => ['HTTPS://Example.COM/Path', 'https://example.com/Path'],
            'drops default https port' => ['https://example.com:443/a', 'https://example.com/a'],
            'drops default http port' => ['http://example.com:80/a', 'http://example.com/a'],
            'keeps non-default port' => ['https://example.com:8443/a', 'https://example.com:8443/a'],
            'drops fragment' => ['https://example.com/a#section', 'https://example.com/a'],
            'strips trailing slash' => ['https://example.com/a/b/', 'https://example.com/a/b'],
            'keeps root slash' => ['https://example.com', 'https://example.com/'],
            'strips utm params' => ['https://example.com/a?utm_source=x&utm_medium=y&id=5', 'https://example.com/a?id=5'],
            'strips click ids' => ['https://example.com/a?fbclid=1&gclid=2&mc_eid=3', 'https://example.com/a'],
            'sorts remaining query' => ['https://example.com/a?b=2&a=1', 'https://example.com/a?a=1&b=2'],
            'keeps plain ref by default' => ['https://example.com/a?ref=home', 'https://example.com/a?ref=home'],
            'keeps path case' => ['https://example.com/CaseSensitive', 'https://example.com/CaseSensitive'],
        ];
    }

    #[Test]
    #[DataProvider('urls')]
    public function it_canonicalizes_urls(string $input, string $expected): void
    {
        $this->assertSame($expected, UrlCanonicalizer::canonicalize($input));
    }

    #[Test]
    public function it_treats_tracking_variants_as_the_same_page(): void
    {
        $this->assertTrue(UrlCanonicalizer::same(
            'https://www.example.com/post/?utm_campaign=news#top',
            'https://www.example.com/post',
        ));
        $this->assertFalse(UrlCanonicalizer::same('https://www.example.com/post', 'https://example.com/post'));
    }

    #[Test]
    public function it_uses_configured_tracking_params(): void
    {
        config(['fetch.list_expansion.tracking_params' => ['ref', 'utm_*']]);

        $this->assertSame('https://example.com/a', UrlCanonicalizer::canonicalize('https://example.com/a?ref=home&utm_source=x'));
    }

    #[Test]
    public function it_returns_unparseable_input_trimmed(): void
    {
        $this->assertSame('not a url', UrlCanonicalizer::canonicalize('  not a url '));
    }
}
