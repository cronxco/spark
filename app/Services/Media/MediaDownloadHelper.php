<?php

namespace App\Services\Media;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Helper service for downloading and attaching media to models with deduplication.
 *
 * This is the main interface that integration jobs should use to handle media.
 */
class MediaDownloadHelper
{
    public function __construct(
        protected MediaDeduplicationService $deduplicationService
    ) {}

    /**
     * Download media from URL and attach to model with MD5 deduplication.
     *
     * @param  Model&HasMedia  $model
     * @param  array<string, mixed>  $customProperties  Additional properties to store with the media
     * @return Media|null Returns Media instance on success, null on failure
     */
    public function downloadAndAttachMedia(
        string $url,
        Model $model,
        string $collection = 'downloaded_images',
        array $customProperties = []
    ): ?Media {
        try {
            // Download the file
            $response = Http::timeout(30)
                ->withOptions(['verify' => config('media-library.media_downloader_ssl', true)])
                ->get($url);

            if (! $response->successful()) {
                Log::warning('Failed to download media from URL', [
                    'url' => $url,
                    'status' => $response->status(),
                    'model' => get_class($model),
                    'model_id' => $model->id,
                ]);

                return null;
            }

            $content = $response->body();

            if (empty($content)) {
                Log::warning('Downloaded media is empty', [
                    'url' => $url,
                    'model' => get_class($model),
                    'model_id' => $model->id,
                ]);

                return null;
            }

            // Calculate MD5 hash
            $md5Hash = $this->deduplicationService->calculateContentHash($content);

            // Check if this file already exists
            $existingMedia = $this->deduplicationService->findExistingMediaByHash($md5Hash);

            if ($existingMedia) {
                Log::debug('Media already exists, attaching reference', [
                    'url' => $url,
                    'md5_hash' => $md5Hash,
                    'existing_media_uuid' => $existingMedia->uuid,
                    'model' => get_class($model),
                    'model_id' => $model->id,
                    'collection' => $collection,
                ]);

                // Attach the existing media to this model
                return $this->deduplicationService->attachMediaToModel(
                    $existingMedia,
                    $model,
                    $collection
                );
            }

            // New file - save it
            $fileName = $this->extractFileNameFromUrl($url);
            $tempPath = $this->saveTempFile($content, $fileName);

            // Add the file to the model with MD5 hash as custom property
            $media = $model->addMedia($tempPath)
                ->withCustomProperties(array_merge(
                    ['md5_hash' => $md5Hash, 'source_url' => $url],
                    $customProperties
                ))
                ->withResponsiveImages()
                ->toMediaCollection($collection);

            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::info('Media downloaded and attached successfully', [
                'url' => $url,
                'md5_hash' => $md5Hash,
                'media_uuid' => $media->uuid,
                'file_name' => $media->file_name,
                'model' => get_class($model),
                'model_id' => $model->id,
                'collection' => $collection,
                'size' => $media->size,
            ]);

            return $media;
        } catch (FileDoesNotExist $e) {
            Log::error('Media file does not exist', [
                'url' => $url,
                'error' => $e->getMessage(),
                'model' => get_class($model),
                'model_id' => $model->id,
            ]);

            return null;
        } catch (FileIsTooBig $e) {
            Log::error('Media file is too big', [
                'url' => $url,
                'error' => $e->getMessage(),
                'model' => get_class($model),
                'model_id' => $model->id,
            ]);

            return null;
        } catch (Exception $e) {
            Log::error('Failed to download and attach media', [
                'url' => $url,
                'error' => $e->getMessage(),
                'model' => get_class($model),
                'model_id' => $model->id,
                'exception' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Download media from base64 string and attach to model with MD5 deduplication.
     *
     * @param  Model&HasMedia  $model
     * @param  array<string, mixed>  $customProperties
     */
    public function attachMediaFromBase64(
        string $base64Data,
        Model $model,
        string $fileName,
        string $collection = 'screenshots',
        array $customProperties = []
    ): ?Media {
        try {
            // Decode base64
            $content = base64_decode($base64Data, strict: true);

            if ($content === false) {
                Log::error('Failed to decode base64 data', [
                    'model' => get_class($model),
                    'model_id' => $model->id,
                ]);

                return null;
            }

            // Calculate MD5 hash
            $md5Hash = $this->deduplicationService->calculateContentHash($content);

            // Check if this file already exists
            $existingMedia = $this->deduplicationService->findExistingMediaByHash($md5Hash);

            if ($existingMedia) {
                Log::debug('Media already exists (from base64), attaching reference', [
                    'md5_hash' => $md5Hash,
                    'existing_media_uuid' => $existingMedia->uuid,
                    'model' => get_class($model),
                    'model_id' => $model->id,
                    'collection' => $collection,
                ]);

                return $this->deduplicationService->attachMediaToModel(
                    $existingMedia,
                    $model,
                    $collection
                );
            }

            // New file - save it
            $tempPath = $this->saveTempFile($content, $fileName);

            $media = $model->addMedia($tempPath)
                ->usingFileName($fileName)
                ->withCustomProperties(array_merge(
                    ['md5_hash' => $md5Hash],
                    $customProperties
                ))
                ->withResponsiveImages()
                ->toMediaCollection($collection);

            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::info('Media attached from base64 successfully', [
                'md5_hash' => $md5Hash,
                'media_uuid' => $media->uuid,
                'file_name' => $media->file_name,
                'model' => get_class($model),
                'model_id' => $model->id,
                'collection' => $collection,
                'size' => $media->size,
            ]);

            return $media;
        } catch (Exception $e) {
            Log::error('Failed to attach media from base64', [
                'error' => $e->getMessage(),
                'model' => get_class($model),
                'model_id' => $model->id,
            ]);

            return null;
        }
    }

    /**
     * Save content to a temporary file and return the path.
     */
    protected function saveTempFile(string $content, string $fileName): string
    {
        $tempDir = storage_path('app/temp');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, recursive: true);
        }

        $tempPath = $tempDir . '/' . uniqid() . '_' . $fileName;
        file_put_contents($tempPath, $content);

        return $tempPath;
    }

    /**
     * Extract a sensible file name from the URL.
     */
    protected function extractFileNameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $fileName = basename($path);

        // If no file name or extension, generate one
        if (empty($fileName) || ! str_contains($fileName, '.')) {
            $fileName = uniqid() . '.jpg'; // Default to .jpg
        }

        return $fileName;
    }
}
