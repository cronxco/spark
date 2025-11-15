<?php

namespace Tests\Unit\Services\Media;

use App\Services\Media\MD5PathGenerator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MD5PathGeneratorTest extends TestCase
{
    protected MD5PathGenerator $pathGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pathGenerator = new MD5PathGenerator;
    }

    /**
     * @test
     */
    public function generates_path_with_md5_hash(): void
    {
        // Create a mock media object with MD5 hash in custom properties
        $media = $this->createMockMedia('abcdef1234567890abcdef1234567890');

        $path = $this->pathGenerator->getPath($media);

        // Should create path structure: ab/cd/hash/
        $this->assertEquals('ab/cd/abcdef1234567890abcdef1234567890/', $path);
    }

    /**
     * @test
     */
    public function generates_conversions_path(): void
    {
        $media = $this->createMockMedia('abcdef1234567890abcdef1234567890');

        $path = $this->pathGenerator->getPathForConversions($media);

        $this->assertEquals('ab/cd/abcdef1234567890abcdef1234567890/conversions/', $path);
    }

    /**
     * @test
     */
    public function generates_responsive_images_path(): void
    {
        $media = $this->createMockMedia('abcdef1234567890abcdef1234567890');

        $path = $this->pathGenerator->getPathForResponsiveImages($media);

        $this->assertEquals('ab/cd/abcdef1234567890abcdef1234567890/responsive/', $path);
    }

    /**
     * @test
     */
    public function uses_different_paths_for_different_hashes(): void
    {
        $media1 = $this->createMockMedia('111111111111111111111111111111');
        $media2 = $this->createMockMedia('222222222222222222222222222222');

        $path1 = $this->pathGenerator->getPath($media1);
        $path2 = $this->pathGenerator->getPath($media2);

        $this->assertNotEquals($path1, $path2);
        $this->assertEquals('11/11/111111111111111111111111111111/', $path1);
        $this->assertEquals('22/22/222222222222222222222222222222/', $path2);
    }

    /**
     * @test
     */
    public function uses_same_path_for_same_hash(): void
    {
        $media1 = $this->createMockMedia('abcdef1234567890abcdef1234567890');
        $media2 = $this->createMockMedia('abcdef1234567890abcdef1234567890');

        $path1 = $this->pathGenerator->getPath($media1);
        $path2 = $this->pathGenerator->getPath($media2);

        $this->assertEquals($path1, $path2);
    }

    /**
     * Create a mock Media object with a given MD5 hash.
     */
    protected function createMockMedia(string $md5Hash): Media
    {
        $media = $this->getMockBuilder(Media::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCustomProperty', 'getPath'])
            ->getMock();

        $media->method('getCustomProperty')
            ->with('md5_hash')
            ->willReturn($md5Hash);

        $media->method('getPath')
            ->willReturn('/fake/path/file.jpg');

        return $media;
    }
}
