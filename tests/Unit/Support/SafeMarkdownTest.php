<?php

namespace Tests\Unit\Support;

use App\Support\SafeMarkdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SafeMarkdownTest extends TestCase
{
    #[Test]
    public function it_preserves_markdown_and_safe_links_while_stripping_raw_html_and_unsafe_links(): void
    {
        $html = SafeMarkdown::render(<<<'MARKDOWN'
**Bold** [secure](https://example.com) [email](mailto:hello@example.com) [unsafe](javascript:alert('xss')) [data](data:text/html,marker)

<script>alert('xss')</script><img src=x onerror=alert('xss')><a href="javascript:marker">raw unsafe link</a><a href="data:text/html,marker">raw data link</a>
MARKDOWN);

        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com">secure</a>', $html);
        $this->assertStringContainsString('<a href="mailto:hello@example.com">email</a>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('href="data:', $html);
    }
}
