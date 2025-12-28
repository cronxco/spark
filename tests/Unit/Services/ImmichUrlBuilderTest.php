<?php

namespace Tests\Unit\Services;

use App\Services\ImmichUrlBuilder;
use Tests\TestCase;

class ImmichUrlBuilderTest extends TestCase
{
    protected ImmichUrlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ImmichUrlBuilder;
    }

    /** @test */
    public function it_builds_asset_url()
    {
        $url = $this->builder->getAssetUrl('https://immich.example.com', 'asset-123');

        $this->assertEquals('https://immich.example.com/photos/asset-123', $url);
    }

    /** @test */
    public function it_builds_asset_url_with_trailing_slash()
    {
        $url = $this->builder->getAssetUrl('https://immich.example.com/', 'asset-123');

        $this->assertEquals('https://immich.example.com/photos/asset-123', $url);
    }

    /** @test */
    public function it_builds_thumbnail_url_with_default_size()
    {
        $url = $this->builder->getThumbnailUrl('https://immich.example.com', 'asset-123');

        $this->assertEquals('https://immich.example.com/api/asset/thumbnail/asset-123?size=preview', $url);
    }

    /** @test */
    public function it_builds_thumbnail_url_with_custom_size()
    {
        $url = $this->builder->getThumbnailUrl('https://immich.example.com', 'asset-123', 'thumbnail');

        $this->assertEquals('https://immich.example.com/api/asset/thumbnail/asset-123?size=thumbnail', $url);
    }

    /** @test */
    public function it_builds_thumbnail_url_with_trailing_slash()
    {
        $url = $this->builder->getThumbnailUrl('https://immich.example.com/', 'asset-123', 'preview');

        $this->assertEquals('https://immich.example.com/api/asset/thumbnail/asset-123?size=preview', $url);
    }

    /** @test */
    public function it_builds_person_url()
    {
        $url = $this->builder->getPersonUrl('https://immich.example.com', 'person-123');

        $this->assertEquals('https://immich.example.com/people/person-123', $url);
    }

    /** @test */
    public function it_builds_person_url_with_trailing_slash()
    {
        $url = $this->builder->getPersonUrl('https://immich.example.com/', 'person-123');

        $this->assertEquals('https://immich.example.com/people/person-123', $url);
    }

    /** @test */
    public function it_handles_special_characters_in_asset_id()
    {
        $url = $this->builder->getAssetUrl('https://immich.example.com', 'asset-with-special-chars-@#$');

        $this->assertEquals('https://immich.example.com/photos/asset-with-special-chars-@#$', $url);
    }

    /** @test */
    public function it_handles_special_characters_in_person_id()
    {
        $url = $this->builder->getPersonUrl('https://immich.example.com', 'person-with-special-chars-@#$');

        $this->assertEquals('https://immich.example.com/people/person-with-special-chars-@#$', $url);
    }

    /** @test */
    public function it_handles_http_protocol()
    {
        $url = $this->builder->getAssetUrl('http://localhost:2283', 'asset-123');

        $this->assertEquals('http://localhost:2283/photos/asset-123', $url);
    }

    /** @test */
    public function it_handles_port_numbers()
    {
        $url = $this->builder->getAssetUrl('https://immich.example.com:2283', 'asset-123');

        $this->assertEquals('https://immich.example.com:2283/photos/asset-123', $url);
    }

    /** @test */
    public function it_handles_subdirectory_installations()
    {
        $url = $this->builder->getAssetUrl('https://example.com/immich', 'asset-123');

        $this->assertEquals('https://example.com/immich/photos/asset-123', $url);
    }

    /** @test */
    public function it_builds_thumbnail_url_for_subdirectory()
    {
        $url = $this->builder->getThumbnailUrl('https://example.com/immich/', 'asset-123', 'preview');

        $this->assertEquals('https://example.com/immich/api/asset/thumbnail/asset-123?size=preview', $url);
    }
}
