<?php

namespace Tests\Unit\Fetch;

use App\Integrations\Fetch\ContentExtractor;
use Tests\TestCase;

class ContentExtractorTest extends TestCase
{
    /** @test */
    public function it_extracts_content_from_valid_html()
    {
        $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <title>Test Article</title>
                <meta name="author" content="John Doe">
            </head>
            <body>
                <article>
                    <h1>Test Article Title</h1>
                    <p>This is a paragraph with more than 100 characters to ensure it passes validation. We need enough content for the extraction to succeed and pass all validation checks.</p>
                    <p>Another paragraph with substantial content to make sure we have enough text for the content extractor to work properly and successfully extract the content.</p>
                </article>
            </body>
            </html>
        ';

        $result = ContentExtractor::extract($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertNotEmpty($result['data']['title']);
        $this->assertNotEmpty($result['data']['content']);
        $this->assertNotEmpty($result['data']['text_content']);
    }

    /** @test */
    public function it_fails_on_missing_title()
    {
        $html = '
            <!DOCTYPE html>
            <html>
            <body>
                <p>Content without a title and more text here to make it long enough for validation purposes. We need substantial content.</p>
            </body>
            </html>
        ';

        $result = ContentExtractor::extract($html, 'https://example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('title', strtolower($result['reason']));
    }

    /** @test */
    public function it_fails_on_insufficient_content()
    {
        $html = '
            <!DOCTYPE html>
            <html>
            <head><title>Short Content Test Article</title></head>
            <body>
                <p>Too short</p>
            </body>
            </html>
        ';

        $result = ContentExtractor::extract($html, 'https://example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('content', strtolower($result['reason']));
    }

    /** @test */
    public function it_detects_robot_check_in_title()
    {
        $html = '
            <!DOCTYPE html>
            <html>
            <head><title>Are you a robot? Please verify you are human to continue browsing this website</title></head>
            <body>
                <p>This content should be ignored because robot check detected. Adding more text here to ensure we have enough content for validation if it gets past the robot check detection phase.</p>
            </body>
            </html>
        ';

        $result = ContentExtractor::extract($html, 'https://example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('robot', strtolower($result['reason']));
    }

    /** @test */
    public function it_detects_paywall()
    {
        $html = '
            <!DOCTYPE html>
            <html>
            <head><title>Premium Article Behind Paywall</title></head>
            <body>
                <div class="paywall">
                    <p>Subscribe to continue reading this article and more content like this one. This is a premium article that requires a subscription to read the full content and access all features.</p>
                </div>
            </body>
            </html>
        ';

        $result = ContentExtractor::extract($html, 'https://example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('paywall', strtolower($result['reason']));
    }

    /** @test */
    public function it_generates_consistent_content_hash()
    {
        $content1 = 'This is test content for hashing';
        $content2 = 'This is test content for hashing';
        $content3 = 'This is different content for hashing';

        $hash1 = ContentExtractor::generateHash($content1);
        $hash2 = ContentExtractor::generateHash($content2);
        $hash3 = ContentExtractor::generateHash($content3);

        $this->assertEquals($hash1, $hash2);
        $this->assertNotEquals($hash1, $hash3);
        $this->assertEquals(64, strlen($hash1)); // SHA256 produces 64 char hex string
    }

    /** @test */
    public function it_detects_paywall_from_common_indicators()
    {
        $paywallIndicators = [
            'subscribe to continue reading',
            'sign up to read',
            'create a free account',
            'subscription required',
            'subscribers only',
        ];

        foreach ($paywallIndicators as $indicator) {
            $html = "
                <!DOCTYPE html>
                <html>
                <head><title>Article Title</title></head>
                <body>
                    <p>{$indicator} to access this premium content and enjoy unlimited articles. Get started today with our flexible subscription plans.</p>
                </body>
                </html>
            ";

            $result = ContentExtractor::extract($html, 'https://example.com');

            $this->assertFalse($result['success'], "Failed to detect paywall for: {$indicator}");
            $this->assertStringContainsString('paywall', strtolower($result['reason']));
        }
    }

    /** @test */
    public function it_extracts_author_information()
    {
        $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <title>Test Article With Author Information</title>
                <meta name="author" content="Jane Smith">
            </head>
            <body>
                <article>
                    <h1>Article Title</h1>
                    <p>This is substantial content for the article with more than enough characters to pass validation. We need to ensure there is sufficient text.</p>
                </article>
            </body>
            </html>
        ';

        $result = ContentExtractor::extract($html, 'https://example.com');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('author', $result['data']);
    }

    /** @test */
    public function it_handles_malformed_html_gracefully()
    {
        $html = '<html><body><p>Unclosed paragraph and broken HTML structure';

        $result = ContentExtractor::extract($html, 'https://example.com');

        // Should fail but not throw an exception
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('reason', $result);
    }
}
