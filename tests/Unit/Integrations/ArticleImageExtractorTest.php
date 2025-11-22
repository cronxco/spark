<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Fetch\ArticleImageExtractor;
use Tests\TestCase;

class ArticleImageExtractorTest extends TestCase
{
    /** @test */
    public function it_extracts_open_graph_image()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta property="og:image" content="https://example.com/og-image.jpg">
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/og-image.jpg', $result);
    }

    /** @test */
    public function it_extracts_twitter_image_when_no_og_image()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta name="twitter:image" content="https://example.com/twitter-image.jpg">
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/twitter-image.jpg', $result);
    }

    /** @test */
    public function it_prefers_og_image_over_twitter_image()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta property="og:image" content="https://example.com/og-image.jpg">
    <meta name="twitter:image" content="https://example.com/twitter-image.jpg">
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/og-image.jpg', $result);
    }

    /** @test */
    public function it_extracts_schema_org_image_from_json_ld()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test Article</title>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": "Test Article",
        "image": "https://example.com/schema-image.jpg"
    }
    </script>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/schema-image.jpg', $result);
    }

    /** @test */
    public function it_extracts_schema_org_image_object()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test Article</title>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "NewsArticle",
        "headline": "Test Article",
        "image": {
            "@type": "ImageObject",
            "url": "https://example.com/schema-image-object.jpg"
        }
    }
    </script>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/schema-image-object.jpg', $result);
    }

    /** @test */
    public function it_handles_relative_urls()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta property="og:image" content="/images/og-image.jpg">
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/images/og-image.jpg', $result);
    }

    /** @test */
    public function it_handles_protocol_relative_urls()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta property="og:image" content="//cdn.example.com/image.jpg">
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://cdn.example.com/image.jpg', $result);
    }

    /** @test */
    public function it_returns_null_for_empty_html()
    {
        $result = ArticleImageExtractor::extract('', 'https://example.com/article');

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_when_no_image_found()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content with no images.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertNull($result);
    }

    /** @test */
    public function it_skips_logo_images_in_fallback()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <img src="/logo.png" alt="Logo" width="100" height="50">
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertNull($result);
    }

    /** @test */
    public function it_extracts_largest_content_image_as_fallback()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <img src="/small-image.jpg" width="100" height="100">
        <img src="/large-image.jpg" width="800" height="600">
        <img src="/medium-image.jpg" width="400" height="300">
        <p>This is the article content with images.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/large-image.jpg', $result);
    }

    /** @test */
    public function it_handles_og_image_url_variant()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta property="og:image:url" content="https://example.com/og-image-url.jpg">
    <title>Test Article</title>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/og-image-url.jpg', $result);
    }

    /** @test */
    public function it_handles_graph_structure_in_json_ld()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test Article</title>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "WebPage",
                "name": "Test Page"
            },
            {
                "@type": "Article",
                "headline": "Test Article",
                "image": "https://example.com/graph-image.jpg"
            }
        ]
    }
    </script>
</head>
<body>
    <article>
        <h1>Test Article</h1>
        <p>This is the article content.</p>
    </article>
</body>
</html>
HTML;

        $result = ArticleImageExtractor::extract($html, 'https://example.com/article');

        $this->assertEquals('https://example.com/graph-image.jpg', $result);
    }
}
