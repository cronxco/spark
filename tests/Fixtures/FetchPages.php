<?php

namespace Tests\Fixtures;

/**
 * Representative pages and emails for link extraction and list detection tests.
 */
class FetchPages
{
    /**
     * A blog index: site navigation, a primary list of posts (each with an
     * image link, a headline link, a date and a "Read more" link to the same
     * post), a "Popular posts" sidebar and a footer.
     *
     * @param  list<string>|null  $slugs
     */
    public static function blogIndex(?array $slugs = null, bool $withSponsoredCard = false): string
    {
        $slugs ??= array_map(fn (int $i): string => "post-number-{$i}-about-something", range(1, 8));

        $items = '';
        foreach ($slugs as $index => $slug) {
            $title = ucfirst(str_replace('-', ' ', $slug));
            $items .= <<<HTML
                <article class="post-card">
                    <a class="thumb" href="/2026/09/{$slug}"><img src="/img/{$index}.jpg" alt=""></a>
                    <h2 class="post-title"><a href="/2026/09/{$slug}?utm_source=home">{$title}</a></h2>
                    <time datetime="2026-09-0{$index}">September {$index}</time>
                    <p>A short excerpt describing {$title} in a sentence or two.</p>
                    <a class="more" href="/2026/09/{$slug}">Read more</a>
                    <a class="author" href="/author/jane">Jane Doe</a>
                </article>
            HTML;

            if ($withSponsoredCard && $index === 2) {
                $items .= <<<'HTML'
                    <article class="post-card">
                        <a class="thumb" href="/2026/09/partner-content-buy-our-widget"><img src="/img/ad.jpg" alt=""></a>
                        <h2 class="post-title"><a href="/2026/09/partner-content-buy-our-widget">Sponsored: the widget that changes everything</a></h2>
                        <time datetime="2026-09-03">September 3</time>
                        <p>Paid partnership.</p>
                        <a class="more" href="/2026/09/partner-content-buy-our-widget">Read more</a>
                    </article>
                HTML;
            }
        }

        $popular = '';
        foreach (range(1, 5) as $i) {
            $popular .= "<li><a href=\"/2026/08/popular-older-post-{$i}\">Popular older post {$i} that everyone read</a></li>";
        }

        return <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <title>The Example Blog — Latest posts</title>
                <link rel="alternate" type="application/rss+xml" href="/feed.xml">
                <meta property="og:type" content="website">
                <link rel="stylesheet" href="/style.css">
            </head>
            <body>
                <header class="site-header">
                    <nav class="main-nav">
                        <a href="/">Home</a><a href="/about">About</a><a href="/archive">Archive</a>
                        <a href="/topics/tech">Tech</a><a href="/topics/science">Science</a><a href="/contact">Contact</a>
                    </nav>
                </header>
                <main>
                    <h1>Latest posts</h1>
                    <section class="post-list">{$items}</section>
                </main>
                <aside class="sidebar">
                    <h3>Popular posts</h3>
                    <ul class="popular">{$popular}</ul>
                </aside>
                <footer><a href="/privacy">Privacy</a><a href="/terms">Terms</a><a href="https://twitter.com/example">Twitter</a></footer>
            </body>
            </html>
        HTML;
    }

    /**
     * A single article with a "Related stories" rail underneath.
     */
    public static function articleWithRelatedRail(): string
    {
        $paragraphs = str_repeat('<p>This is a long paragraph of genuine article prose that goes on for a while so that Readability recognises it as the main content of the page and extracts it cleanly.</p>', 12);

        $related = '';
        foreach (range(1, 5) as $i) {
            $related .= "<li><a href=\"/2026/07/related-story-number-{$i}\">Related story number {$i} you might enjoy</a></li>";
        }

        return <<<HTML
            <!DOCTYPE html>
            <html>
            <head><title>A thoughtful essay about testing software</title><meta property="og:type" content="article"></head>
            <body>
                <nav><a href="/">Home</a><a href="/about">About</a></nav>
                <article>
                    <h1>A thoughtful essay about testing software</h1>
                    {$paragraphs}
                </article>
                <section class="related-stories">
                    <h2>Related stories</h2>
                    <ul>{$related}</ul>
                </section>
            </body>
            </html>
        HTML;
    }

    /**
     * A TLDR-style link digest email: tracked story links, a sponsor block,
     * social links and the usual housekeeping links.
     */
    public static function digestNewsletter(): string
    {
        $stories = '';
        foreach (range(1, 6) as $i) {
            $stories .= <<<HTML
                <tr><td class="story">
                    <a href="https://tracking.example-mail.com/c/{$i}abc"><strong>Big story number {$i} about the industry (4 minute read)</strong></a>
                    <p>A two sentence summary of story {$i} written by the newsletter editors.</p>
                </td></tr>
            HTML;
        }

        return <<<HTML
            <html><body>
                <table>
                    <tr><td><a href="https://news.example.com/view/123">View in browser</a></td></tr>
                    {$stories}
                    <tr><td class="sponsor"><a href="https://tracking.example-mail.com/c/sponsor">Sponsor: Try Widgetly free for 30 days</a></td></tr>
                    <tr><td>
                        <a href="https://twitter.com/intent/tweet?text=hi">Share on Twitter</a>
                        <a href="https://news.example.com/refer?id=abc">Refer a friend</a>
                        <a href="https://news.example.com/unsubscribe?u=abc">Unsubscribe</a>
                        <a href="mailto:editor@example.com">Email us</a>
                    </td></tr>
                </table>
            </body></html>
        HTML;
    }
}
