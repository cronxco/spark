<?php

namespace App\Services\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Service for handling media deduplication.
 *
 * Ensures that identical files (by MD5 hash) are only stored once in S3,
 * while multiple models can reference the same media file.
 */
class MediaDeduplicationService
{
    /**
     * Find existing media by MD5 hash.
     */
    public function findExistingMediaByHash(string $md5Hash): ?Media
    {
        return Media::where('custom_properties->md5_hash', $md5Hash)->first();
    }

    /**
     * Attach media to a model by creating a new database record that references the same file.
     *
     * This creates a new media record without uploading the file again,
     * allowing multiple models to reference the same S3 file (via same md5_hash = same path).
     *
     * @param  Model&HasMedia  $model
     */
    public function attachMediaToModel(
        Media $media,
        Model $model,
        string $collection = 'default'
    ): Media {
        // Check if this model already has a media with this hash in this collection
        $md5Hash = $media->getCustomProperty('md5_hash');

        $existingAttachment = $model->media()
            ->where('collection_name', $collection)
            ->where('custom_properties->md5_hash', $md5Hash)
            ->first();

        if ($existingAttachment) {
            Log::debug('Media already attached to model', [
                'model' => get_class($model),
                'model_id' => $model->id,
                'media_uuid' => $existingAttachment->uuid,
                'collection' => $collection,
                'md5_hash' => $md5Hash,
            ]);

            return $existingAttachment;
        }

        // Create a new media record that references the same file
        // By copying all the attributes and using the same md5_hash,
        // the MD5PathGenerator will ensure it points to the same S3 path
        $newMedia = $media->replicate([
            'uuid', // Generate new UUID
            'created_at',
            'updated_at',
        ]);

        $newMedia->model_type = get_class($model);
        $newMedia->model_id = $model->id;
        $newMedia->collection_name = $collection;
        $newMedia->uuid = Str::uuid();
        $newMedia->save();

        Log::debug('Media attached to model via database replication (no upload)', [
            'model' => get_class($model),
            'model_id' => $model->id,
            'original_media_uuid' => $media->uuid,
            'new_media_uuid' => $newMedia->uuid,
            'collection' => $collection,
            'md5_hash' => $md5Hash,
            'file_name' => $newMedia->file_name,
        ]);

        return $newMedia;
    }

    /**
     * Get the count of models referencing a specific media file (by MD5 hash).
     */
    public function getMediaReferenceCount(Media $media): int
    {
        $md5Hash = $media->getCustomProperty('md5_hash');

        if (! $md5Hash) {
            // If no hash, just count this specific media record
            return 1;
        }

        // Count all media records with the same MD5 hash
        return Media::where('custom_properties->md5_hash', $md5Hash)->count();
    }

    /**
     * Check if media can be safely deleted.
     *
     * Media can be deleted if this is the last reference to the file.
     */
    public function canDeleteMedia(Media $media): bool
    {
        return $this->getMediaReferenceCount($media) <= 1;
    }

    /**
     * Delete media record, and optionally the file if it's the last reference.
     */
    public function deleteMedia(Media $media, bool $forceDelete = false): void
    {
        $md5Hash = $media->getCustomProperty('md5_hash');
        $referenceCount = $this->getMediaReferenceCount($media);

        if ($forceDelete) {
            Log::info('Force deleting media', [
                'media_uuid' => $media->uuid,
                'md5_hash' => $md5Hash,
                'reference_count' => $referenceCount,
            ]);
            $media->forceDelete();

            return;
        }

        if ($referenceCount > 1) {
            // Other models reference this file - just soft delete this record
            Log::debug('Soft deleting media record (other references exist)', [
                'media_uuid' => $media->uuid,
                'md5_hash' => $md5Hash,
                'reference_count' => $referenceCount,
            ]);
            $media->delete();
        } else {
            // This is the last reference - safe to delete the file
            Log::debug('Deleting media record and file (last reference)', [
                'media_uuid' => $media->uuid,
                'md5_hash' => $md5Hash,
            ]);
            $media->delete();
        }
    }

    /**
     * Calculate MD5 hash of a file.
     */
    public function calculateFileHash(string $filePath): string
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        return md5_file($filePath);
    }

    /**
     * Calculate MD5 hash of file contents (string).
     */
    public function calculateContentHash(string $content): string
    {
        return md5($content);
    }
}
