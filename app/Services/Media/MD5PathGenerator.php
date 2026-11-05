<?php

namespace App\Services\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * MD5-based path generator for media deduplication.
 *
 * Generates storage paths based on the MD5 hash of file contents,
 * ensuring that identical files share the same S3 key and preventing duplication.
 *
 * Path structure: {first_2_chars}/{next_2_chars}/{md5_hash}/
 * Example: ab/cd/abcdef123456.../original.jpg
 */
class MD5PathGenerator implements PathGenerator
{
    /**
     * Get the path for the media file.
     */
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media) . '/';
    }

    /**
     * Get the path for conversions of the media file.
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media) . '/conversions/';
    }

    /**
     * Get the path for responsive images of the media file.
     */
    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media) . '/responsive/';
    }

    /**
     * Get the base path using MD5 hash.
     *
     * Uses a directory structure based on the first 4 characters of the MD5 hash
     * to prevent having too many files in a single directory.
     */
    protected function getBasePath(Media $media): string
    {
        $hash = $this->getFileHash($media);

        // Split hash into directory structure: ab/cd/abcdef.../
        $prefix1 = substr($hash, 0, 2);
        $prefix2 = substr($hash, 2, 2);

        return $prefix1 . '/' . $prefix2 . '/' . $hash;
    }

    /**
     * Get the MD5 hash for the media file.
     *
     * Uses the stored 'md5_hash' custom property. Without one, falls back to
     * a hash of the media ID: that path is unique and won't deduplicate.
     *
     * The fallback must not call `$media->getPath()`. That method asks this
     * generator for the path, which came back here, recursing until PHP ran
     * out of memory on any page that showed an unhashed file.
     */
    protected function getFileHash(Media $media): string
    {
        $storedHash = $media->getCustomProperty('md5_hash');

        if ($storedHash) {
            return $storedHash;
        }

        return md5((string) $media->id);
    }
}
