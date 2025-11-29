<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Services\Media\MediaDownloadHelper;
use Exception;
use Illuminate\Support\Facades\Log;

class DownloadImagesToMediaLibraryTask extends BaseTaskJob
{
    /**
     * Execute the task to download external images to Media Library.
     *
     * This task downloads images from:
     * - Block media_url field
     * - Block metadata['image'] and metadata['image_url']
     * - EventObject media_url field
     *
     * Uses MediaDownloadHelper with automatic MD5 deduplication.
     */
    protected function execute(): void
    {
        $mediaHelper = app(MediaDownloadHelper::class);
        $downloadedCount = 0;

        // For Blocks: download from media_url and metadata
        if ($this->model instanceof \App\Models\Block) {
            $downloadedCount += $this->downloadBlockImages($this->model, $mediaHelper);
        }

        // For EventObjects: download from media_url
        if ($this->model instanceof \App\Models\EventObject) {
            $downloadedCount += $this->downloadEventObjectImages($this->model, $mediaHelper);
        }

        // For Events: download from all related blocks and objects
        if ($this->model instanceof \App\Models\Event) {
            $downloadedCount += $this->downloadEventImages($this->model, $mediaHelper);
        }

        if ($downloadedCount > 0) {
            Log::info('DownloadImagesToMediaLibraryTask: Downloaded images', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->id,
                'downloaded_count' => $downloadedCount,
            ]);
        }
    }

    /**
     * Download images for a Block model.
     */
    private function downloadBlockImages(\App\Models\Block $block, MediaDownloadHelper $mediaHelper): int
    {
        $count = 0;

        // Download from media_url if exists and not already in Media Library
        if ($block->media_url && ! $block->hasMedia('downloaded_images')) {
            try {
                $media = $mediaHelper->downloadAndAttachMedia(
                    $block->media_url,
                    $block,
                    'downloaded_images'
                );

                if ($media) {
                    $count++;
                    Log::debug('Downloaded image from media_url for block', [
                        'block_id' => $block->id,
                        'block_type' => $block->block_type,
                        'media_url' => $block->media_url,
                        'media_id' => $media->id,
                    ]);
                }
            } catch (Exception $e) {
                Log::warning('Failed to download image from media_url for block', [
                    'block_id' => $block->id,
                    'media_url' => $block->media_url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Download from metadata['image'] or metadata['image_url']
        if (! $block->hasMedia('downloaded_images')) {
            $metadata = $block->metadata ?? [];
            $imageUrl = $metadata['image'] ?? $metadata['image_url'] ?? null;

            if ($imageUrl) {
                try {
                    $media = $mediaHelper->downloadAndAttachMedia(
                        $imageUrl,
                        $block,
                        'downloaded_images'
                    );

                    if ($media) {
                        $count++;
                        Log::debug('Downloaded image from metadata for block', [
                            'block_id' => $block->id,
                            'block_type' => $block->block_type,
                            'image_url' => $imageUrl,
                            'media_id' => $media->id,
                        ]);
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to download image from metadata for block', [
                        'block_id' => $block->id,
                        'image_url' => $imageUrl,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Download images for an EventObject model.
     */
    private function downloadEventObjectImages(\App\Models\EventObject $object, MediaDownloadHelper $mediaHelper): int
    {
        $count = 0;

        if ($object->media_url && ! $object->hasMedia('downloaded_images')) {
            try {
                $media = $mediaHelper->downloadAndAttachMedia(
                    $object->media_url,
                    $object,
                    'downloaded_images'
                );

                if ($media) {
                    $count++;
                    Log::debug('Downloaded image for EventObject', [
                        'object_id' => $object->id,
                        'object_type' => $object->type,
                        'media_url' => $object->media_url,
                        'media_id' => $media->id,
                    ]);
                }
            } catch (Exception $e) {
                Log::warning('Failed to download image for EventObject', [
                    'object_id' => $object->id,
                    'media_url' => $object->media_url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Download images for an Event and all its related blocks and objects.
     */
    private function downloadEventImages(\App\Models\Event $event, MediaDownloadHelper $mediaHelper): int
    {
        $count = 0;

        // Download images from all blocks
        foreach ($event->blocks as $block) {
            $count += $this->downloadBlockImages($block, $mediaHelper);
        }

        // Download image from target object
        if ($event->target) {
            $count += $this->downloadEventObjectImages($event->target, $mediaHelper);
        }

        // Download image from actor object
        if ($event->actor) {
            $count += $this->downloadEventObjectImages($event->actor, $mediaHelper);
        }

        return $count;
    }
}
