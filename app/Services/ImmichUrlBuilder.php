<?php

namespace App\Services;

class ImmichUrlBuilder
{
    /**
     * Get URL to view asset in Immich
     */
    public function getAssetUrl(string $serverUrl, string $assetId): string
    {
        return rtrim($serverUrl, '/')."/photos/{$assetId}";
    }

    /**
     * Get URL for asset thumbnail
     * Size options: 'preview' (default), 'thumbnail', 'original'
     */
    public function getThumbnailUrl(string $serverUrl, string $assetId, string $size = 'preview'): string
    {
        return rtrim($serverUrl, '/')."/api/asset/thumbnail/{$assetId}?size={$size}";
    }

    /**
     * Get URL to view person in Immich
     */
    public function getPersonUrl(string $serverUrl, string $personId): string
    {
        return rtrim($serverUrl, '/')."/people/{$personId}";
    }

    /**
     * Get URL for person thumbnail
     */
    public function getPersonThumbnailUrl(string $serverUrl, string $personId): string
    {
        return rtrim($serverUrl, '/')."/api/people/{$personId}/thumbnail";
    }

    /**
     * Get URL to view album in Immich
     */
    public function getAlbumUrl(string $serverUrl, string $albumId): string
    {
        return rtrim($serverUrl, '/')."/albums/{$albumId}";
    }
}
