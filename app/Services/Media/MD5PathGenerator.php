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
     * If the custom property 'md5_hash' exists, use it.
     * Otherwise, generate it from the file (fallback for edge cases).
     */
    protected function getFileHash(Media $media): string
    {
        // Check if MD5 hash was stored as a custom property
        $storedHash = $media->getCustomProperty('md5_hash');

        if ($storedHash) {
            return $storedHash;
        }

        // Fallback: calculate hash from file (should rarely happen)
        $path = $media->getPath();

        if (file_exists($path)) {
            return md5_file($path);
        }

        // Last resort: use media ID (this prevents errors but won't deduplicate)
        return md5((string) $media->id);
    }
}
