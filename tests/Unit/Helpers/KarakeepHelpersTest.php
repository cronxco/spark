<?php

namespace Tests\Unit\Helpers;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class KarakeepHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function truncate_to_words_returns_original_if_under_limit(): void
    {
        $text = 'This is a short text with only ten words here.';
        $result = truncate_to_words($text, 150);

        $this->assertEquals($text, $result);
    }

    /**
     * @test
     */
    public function truncate_to_words_truncates_long_text(): void
    {
        $text = str_repeat('word ', 200); // 200 words
        $result = truncate_to_words($text, 150);

        $this->assertStringEndsWith('...', $result);
        $wordCount = str_word_count($result);
        $this->assertLessThanOrEqual(155, $wordCount);
    }

    /**
     * @test
     */
    public function truncate_to_words_handles_custom_limit(): void
    {
        $text = str_repeat('word ', 100);
        $result = truncate_to_words($text, 50);

        $this->assertStringEndsWith('...', $result);
        $wordCount = str_word_count($result);
        $this->assertLessThanOrEqual(55, $wordCount);
    }

    /**
     * @test
     */
    public function truncate_to_words_handles_empty_string(): void
    {
        $result = truncate_to_words('', 150);
        $this->assertEquals('', $result);
    }

    /**
     * @test
     */
    public function karakeep_add_bookmark_returns_null_when_config_missing(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Karakeep configuration missing', Mockery::any());

        config(['services.karakeep.url' => null]);
        config(['services.karakeep.access_token' => null]);

        $result = karakeep_add_bookmark('https://example.com');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function karakeep_add_bookmark_posts_to_api_successfully(): void
    {
        config([
            'services.karakeep.url' => 'https://karakeep.test',
            'services.karakeep.access_token' => 'test_token',
        ]);

        Http::fake([
            'https://karakeep.test/api/v1/bookmarks' => Http::response([
                'id' => 'bookmark123',
                'url' => 'https://example.com',
                'title' => 'Example',
            ], 200),
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Successfully added bookmark to Karakeep', Mockery::any());

        $result = karakeep_add_bookmark('https://example.com', 'Example', ['tag1', 'tag2']);

        $this->assertIsArray($result);
        $this->assertEquals('bookmark123', $result['id']);
        $this->assertEquals('https://example.com', $result['url']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://karakeep.test/api/v1/bookmarks' &&
                   $request->method() === 'POST' &&
                   $request['url'] === 'https://example.com' &&
                   $request['title'] === 'Example' &&
                   $request['tags'] === ['tag1', 'tag2'];
        });
    }

    /**
     * @test
     */
    public function karakeep_add_bookmark_handles_api_failure(): void
    {
        config([
            'services.karakeep.url' => 'https://karakeep.test',
            'services.karakeep.access_token' => 'test_token',
        ]);

        Http::fake([
            'https://karakeep.test/api/v1/bookmarks' => Http::response([
                'error' => 'Invalid request',
            ], 400),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to add bookmark to Karakeep', Mockery::any());

        $result = karakeep_add_bookmark('https://example.com');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function karakeep_add_bookmark_handles_exception(): void
    {
        config([
            'services.karakeep.url' => 'https://karakeep.test',
            'services.karakeep.access_token' => 'test_token',
        ]);

        Http::fake(function () {
            throw new Exception('Network error');
        });

        Log::shouldReceive('error')
            ->once()
            ->with('Exception while adding bookmark to Karakeep', Mockery::any());

        $result = karakeep_add_bookmark('https://example.com');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function karakeep_add_bookmark_with_minimal_parameters(): void
    {
        config([
            'services.karakeep.url' => 'https://karakeep.test',
            'services.karakeep.access_token' => 'test_token',
        ]);

        Http::fake([
            'https://karakeep.test/api/v1/bookmarks' => Http::response([
                'id' => 'bookmark123',
            ], 200),
        ]);

        Log::shouldReceive('info')->once();

        $result = karakeep_add_bookmark('https://example.com');

        $this->assertIsArray($result);

        Http::assertSent(function ($request) {
            return $request['url'] === 'https://example.com' &&
                   ! isset($request['title']) &&
                   ! isset($request['tags']);
        });
    }

    /**
     * @test
     */
    public function karakeep_add_bookmark_trims_trailing_slash_from_url(): void
    {
        config([
            'services.karakeep.url' => 'https://karakeep.test/',
            'services.karakeep.access_token' => 'test_token',
        ]);

        Http::fake([
            'https://karakeep.test/api/v1/bookmarks' => Http::response(['id' => 'bookmark123'], 200),
        ]);

        Log::shouldReceive('info')->once();

        karakeep_add_bookmark('https://example.com');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://karakeep.test/api/v1/bookmarks';
        });
    }
}
