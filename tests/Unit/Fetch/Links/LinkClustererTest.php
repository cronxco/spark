<?php

namespace Tests\Unit\Fetch\Links;

use App\Services\Fetch\Links\LinkCandidate;
use App\Services\Fetch\Links\LinkCandidateExtractor;
use App\Services\Fetch\Links\LinkClusterer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\FetchPages;
use Tests\TestCase;

class LinkClustererTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function templates(): array
    {
        return [
            'dated post' => ['https://www.example.com/2026/09/my-great-post', 'example.com/{n}/{n}/{slug}'],
            'substack post' => ['https://example.substack.com/p/some-post', 'example.substack.com/p/{slug}'],
            'category page' => ['https://example.com/topics/tech', 'example.com/topics/{slug}'],
            'numeric id' => ['https://news.example.com/item/12345', 'news.example.com/item/{n}'],
            'long slug mid path' => ['https://example.com/a-very-long-section-name/post', 'example.com/{slug}/{slug}'],
            'root' => ['https://example.com/', 'example.com/'],
        ];
    }

    #[Test]
    public function it_builds_a_group_from_json_ld_item_lists_linked_on_the_page(): void
    {
        $jsonLd = json_encode(['@type' => 'ItemList', 'itemListElement' => [
            ['url' => '/p/first-story'], ['url' => '/p/second-story'], ['url' => '/p/not-on-page'],
        ]]);
        $html = "<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body>"
            . '<div><a href="/p/second-story">The second story headline</a></div>'
            . '<section><a href="/p/first-story">The first story headline</a></section></body></html>';

        $page = (new LinkCandidateExtractor)->fromHtml($html, 'https://example.com/');
        $cluster = (new LinkClusterer)->structuredCluster($page);

        $this->assertNotNull($cluster);
        $this->assertSame('s0', $cluster->id);
        $this->assertSame(['The first story headline', 'The second story headline'], array_map(fn (LinkCandidate $item): string => $item->anchorText, $cluster->items));
    }

    #[Test]
    public function it_ignores_json_ld_lists_that_are_mostly_not_on_the_page(): void
    {
        $jsonLd = json_encode(['@type' => 'ItemList', 'itemListElement' => [
            ['url' => '/p/a'], ['url' => '/p/b'], ['url' => '/p/c'], ['url' => '/p/d'], ['url' => '/p/e'],
        ]]);
        $html = "<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body>"
            . '<a href="/p/a">Story A headline</a><a href="/p/b">Story B headline</a></body></html>';

        $page = (new LinkCandidateExtractor)->fromHtml($html, 'https://example.com/');

        $this->assertNull((new LinkClusterer)->structuredCluster($page));
    }

    #[Test]
    public function it_ranks_the_primary_post_list_first(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $clusters = (new LinkClusterer(minItems: 5))->cluster($page);

        $primary = $clusters[0];
        $this->assertSame('c0', $primary->id);
        $this->assertSame('blog.example.com/{n}/{n}/{slug}', $primary->urlTemplate);
        $this->assertSame(8, $primary->count());
        $this->assertSame([], $primary->landmarks);
        $this->assertSame(1.0, $primary->headingRatio);
        $this->assertStringStartsWith('Post number 1', $primary->items[0]->anchorText);
    }

    #[Test]
    public function it_excludes_navigation_and_footer_links(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        foreach ((new LinkClusterer(minItems: 3))->cluster($page) as $cluster) {
            foreach ($cluster->items as $item) {
                $this->assertFalse($item->hasLandmark('nav', 'footer'), "{$item->url} should have been excluded");
            }
        }
    }

    #[Test]
    public function it_keeps_the_sidebar_as_a_penalised_separate_cluster(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $clusters = (new LinkClusterer(minItems: 5))->cluster($page);

        $this->assertCount(2, $clusters);
        $this->assertContains('aside', $clusters[1]->landmarks);
        $this->assertSame('Popular posts', $clusters[1]->precedingHeading);
        $this->assertLessThan($clusters[0]->score, $clusters[1]->score);
    }

    #[Test]
    public function it_merges_image_headline_and_read_more_links_to_one_item_per_post(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $primary = (new LinkClusterer(minItems: 5))->cluster($page)[0];

        $urls = array_map(fn (LinkCandidate $item): string => strtok($item->url, '?'), $primary->items);
        $this->assertSame($urls, array_values(array_unique($urls)));
    }

    #[Test]
    public function it_keeps_a_stable_signature_when_the_posts_change(): void
    {
        $clusterer = new LinkClusterer(minItems: 5);
        $extractor = new LinkCandidateExtractor;

        $before = $clusterer->cluster($extractor->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/'))[0];
        $after = $clusterer->cluster($extractor->fromHtml(
            FetchPages::blogIndex(array_map(fn (int $i): string => "a-brand-new-post-{$i}", range(1, 8))),
            'https://blog.example.com/',
        ))[0];

        $this->assertSame($before->signature, $after->signature);
    }

    #[Test]
    public function it_drops_groups_smaller_than_the_minimum(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $this->assertSame([], (new LinkClusterer(minItems: 20))->cluster($page));
    }

    #[Test]
    public function it_limits_the_number_of_clusters(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $this->assertCount(1, (new LinkClusterer(minItems: 5, maxClusters: 1))->cluster($page));
    }

    #[Test]
    public function it_considers_a_blog_index_list_like(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');
        $clusterer = new LinkClusterer(minItems: 5);

        $this->assertTrue($clusterer->looksListLike($page, $clusterer->cluster($page), 400));
    }

    #[Test]
    public function it_does_not_consider_a_long_article_with_a_related_rail_list_like(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::articleWithRelatedRail(), 'https://blog.example.com/2026/09/essay');
        $clusterer = new LinkClusterer(minItems: 5);
        $clusters = $clusterer->cluster($page);

        $this->assertCount(1, $clusters);
        $this->assertContains('related', $clusters[0]->landmarks);
        $this->assertFalse($clusterer->looksListLike($page, $clusters, 2000));
    }

    #[Test]
    public function it_describes_clusters_without_raw_numbers_for_jev(): void
    {
        $page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(), 'https://blog.example.com/');

        $state = (new LinkClusterer(minItems: 5))->cluster($page)[0]->toState();

        $this->assertSame('several (6-15)', $state['size']);
        $this->assertSame('almost all', $state['links_are_headlines']);
        $this->assertSame('main content', $state['page_regions']);
        $this->assertCount(5, $state['example_link_texts']);
    }

    #[Test]
    #[DataProvider('templates')]
    public function it_builds_url_templates(string $url, string $expected): void
    {
        $this->assertSame($expected, LinkClusterer::urlTemplate($url));
    }
}
