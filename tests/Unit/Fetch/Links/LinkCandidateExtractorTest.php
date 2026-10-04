<?php

namespace Tests\Unit\Fetch\Links;

use App\Services\Fetch\Links\LinkCandidate;
use App\Services\Fetch\Links\LinkCandidateExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\FetchPages;
use Tests\TestCase;

class LinkCandidateExtractorTest extends TestCase
{
    #[Test]
    public function it_extracts_page_signals_and_feeds(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $this->assertSame('The Example Blog — Latest posts', $page->title);
        $this->assertSame('Latest posts', $page->h1);
        $this->assertSame('website', $page->ogType);
        $this->assertSame(['https://blog.example.com/feed.xml'], $page->feedUrls);
    }

    #[Test]
    public function it_records_landmarks_headings_and_dates(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $navLink = $this->firstWithUrl($page->candidates, 'https://blog.example.com/about');
        $this->assertContains('nav', $navLink->landmarks);
        $this->assertContains('header', $navLink->landmarks);

        $headline = $this->firstWithUrl($page->candidates, 'https://blog.example.com/2026/09/post-number-1-about-something?utm_source=home');
        $this->assertTrue($headline->inHeading);
        $this->assertTrue($headline->nearTime);
        $this->assertSame('Latest posts', $headline->precedingHeading);
        $this->assertSame([], $headline->landmarks);

        $popular = $this->firstWithUrl($page->candidates, 'https://blog.example.com/2026/08/popular-older-post-1');
        $this->assertContains('aside', $popular->landmarks);
        $this->assertSame('Popular posts', $popular->precedingHeading);

        $footer = $this->firstWithUrl($page->candidates, 'https://twitter.com/example');
        $this->assertContains('footer', $footer->landmarks);
    }

    #[Test]
    public function it_marks_image_only_links(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $imageLinks = array_filter($page->candidates, fn (LinkCandidate $c): bool => $c->imageOnly);

        $this->assertCount(8, $imageLinks);
    }

    #[Test]
    public function it_skips_non_web_self_and_asset_links(): void
    {
        $html = <<<'HTML'
            <html><body>
                <a href="#top">Top</a>
                <a href="https://example.com/page">Self</a>
                <a href="mailto:a@example.com">Mail</a>
                <a href="javascript:void(0)">JS</a>
                <a href="/logo.png">Logo</a>
                <a href="/article">Article link</a>
            </body></html>
        HTML;

        $page = (new LinkCandidateExtractor)->fromHtml($html, 'https://example.com/page');

        $this->assertSame(['https://example.com/article'], array_map(fn (LinkCandidate $c): string => $c->url, $page->candidates));
    }

    #[Test]
    public function it_resolves_links_against_base_href(): void
    {
        $html = '<html><head><base href="/news/"></head><body><a href="story-one">Story one</a></body></html>';

        $page = (new LinkCandidateExtractor)->fromHtml($html, 'https://example.com/index.html');

        $this->assertSame('https://example.com/news/story-one', $page->candidates[0]->url);
    }

    #[Test]
    public function it_reads_json_ld_item_lists(): void
    {
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [[
                '@type' => 'ItemList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'url' => '/posts/one'],
                    ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => 'https://example.com/posts/two']],
                ],
            ]],
        ]);

        $html = "<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body></body></html>";

        $page = (new LinkCandidateExtractor)->fromHtml($html, 'https://example.com/');

        $this->assertSame(['https://example.com/posts/one', 'https://example.com/posts/two'], $page->structuredItemUrls);
    }

    #[Test]
    public function it_returns_an_empty_result_for_empty_html(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml('', 'https://example.com/');

        $this->assertSame([], $page->candidates);
        $this->assertNull($page->title);
    }

    /**
     * @param  list<LinkCandidate>  $candidates
     */
    private function firstWithUrl(array $candidates, string $url): LinkCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->url === $url) {
                return $candidate;
            }
        }

        $this->fail("No candidate for {$url}");
    }
}
