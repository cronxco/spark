<?php

namespace Tests\Unit\Fetch\Links;

use App\Services\Fetch\Links\LinkCandidate;
use App\Services\Fetch\Links\LinkCandidateExtractor;
use App\Services\Fetch\Links\NewsletterLinkFilter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\FetchPages;
use Tests\TestCase;

class NewsletterLinkFilterTest extends TestCase
{
    #[Test]
    public function it_keeps_stories_and_the_sponsor_but_drops_housekeeping(): void
    {
        $urls = $this->filtered(FetchPages::digestNewsletter());

        $this->assertSame([
            'https://tracking.example-mail.com/c/1abc',
            'https://tracking.example-mail.com/c/2abc',
            'https://tracking.example-mail.com/c/3abc',
            'https://tracking.example-mail.com/c/4abc',
            'https://tracking.example-mail.com/c/5abc',
            'https://tracking.example-mail.com/c/6abc',
            'https://tracking.example-mail.com/c/7abc',
        ], $urls);
    }

    #[Test]
    public function it_drops_list_unsubscribe_urls_whatever_their_anchor_says(): void
    {
        $html = '<a href="https://mail.example.com/x/abc">Big story about the economy this week</a>';

        $this->assertSame([], $this->filtered($html, ['https://mail.example.com/x/abc']));
    }

    #[Test]
    public function it_keeps_headlines_that_merely_mention_housekeeping_words(): void
    {
        $html = '<a href="https://news.example.org/why-nobody-wants-to-subscribe-to-streaming">Why nobody wants to subscribe to another streaming service anymore</a>'
            . '<a href="https://news.example.org/profile-of-a-founder">A profile of a founder who built a company twice</a>'
            . '<a href="https://news.example.org/subscribe">Subscribe</a>'
            . '<a href="https://news.example.org/a/manage-preferences">Update your preferences</a>';

        $this->assertSame([
            'https://news.example.org/why-nobody-wants-to-subscribe-to-streaming',
            'https://news.example.org/profile-of-a-founder',
        ], $this->filtered($html));
    }

    #[Test]
    public function it_merges_image_and_text_links_to_the_same_story(): void
    {
        $html = '<a href="https://news.example.org/story"><img src="x.png"></a>'
            . '<a href="https://news.example.org/story">The story headline</a>'
            . '<a href="https://news.example.org/only-an-image"><img src="y.png"></a>';

        $filter = new NewsletterLinkFilter;
        $page = (new LinkCandidateExtractor)->fromHtml($html, 'https://newsletter.invalid/');
        $links = $filter->filter($page->candidates);

        $this->assertCount(1, $links);
        $this->assertSame('The story headline', $links[0]->anchorText);
        $this->assertSame('l0', $links[0]->id);
    }

    /**
     * @param  list<string>  $listUnsubscribe
     * @return list<string>
     */
    private function filtered(string $html, array $listUnsubscribe = []): array
    {
        $page = (new LinkCandidateExtractor)->fromHtml($html, 'https://newsletter.invalid/');

        return array_map(fn (LinkCandidate $link): string => $link->url, (new NewsletterLinkFilter)->filter($page->candidates, $listUnsubscribe));
    }
}
