<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RenderMarkdownTest extends TestCase
{
    #[Test]
    public function block_markdown_strips_raw_html_and_unsafe_links(): void
    {
        $html = render_markdown("<img src=x onerror=alert(1)>\n\n[Click](javascript:alert(1)) and [safe](https://example.com)");

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<a href="https://example.com">safe</a>', $html);
    }

    #[Test]
    public function inline_markdown_follows_the_same_policy(): void
    {
        $html = render_inline_markdown('**Bold** <b onmouseover="x()">raw</b> [bad](vbscript:msgbox) [ok](https://example.com)');

        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
        $this->assertStringNotContainsString('vbscript:', $html);
        $this->assertStringContainsString('<a href="https://example.com">ok</a>', $html);
        $this->assertStringNotContainsString('<p>', $html);
    }

    #[Test]
    public function empty_input_renders_nothing(): void
    {
        $this->assertSame('', render_inline_markdown(null));
        $this->assertSame('', render_inline_markdown('   '));
        $this->assertSame('', render_markdown(null));
    }
}
