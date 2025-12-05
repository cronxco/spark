<?php

namespace App\Jobs\Data\Untappd;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use App\Models\Integration;

class UntappdCreateBeerInfoBlocks extends BaseProcessingJob
{
    public function __construct(
        Integration $integration,
        array $rawData
    ) {
        parent::__construct($integration, $rawData);
    }

    protected function getServiceName(): string
    {
        return 'untappd';
    }

    protected function getJobType(): string
    {
        return 'create_beer_info_blocks';
    }

    protected function process(): void
    {
        $beerId = $this->rawData['beer_id'] ?? null;

        if (! $beerId) {
            logger()->warning('Missing beer_id for beer info block creation');

            return;
        }

        $beer = EventObject::find($beerId);

        if (! $beer) {
            logger()->warning('Beer EventObject not found', ['beer_id' => $beerId]);

            return;
        }

        logger()->info('Creating beer info blocks', [
            'beer_id' => $beer->id,
            'beer_title' => $beer->title,
        ]);

        // Find all events where this beer is the target and don't already have a beer_details block
        $events = $beer->targetEvents()
            ->whereDoesntHave('blocks', function ($query) {
                $query->where('block_type', 'beer_details');
            })
            ->get();

        logger()->info('Found events needing beer info blocks', [
            'beer_id' => $beer->id,
            'event_count' => $events->count(),
        ]);

        foreach ($events as $event) {
            $this->createBeerInfoBlock($event, $beer);
        }
    }

    private function createBeerInfoBlock($event, EventObject $beer): void
    {
        $metadata = $beer->metadata ?? [];

        // Build block metadata
        $blockMetadata = [
            'description' => $metadata['description'] ?? null,
            'style' => $metadata['style'] ?? null,
            'abv' => $metadata['abv'] ?? null,
            'ibu' => $metadata['ibu'] ?? null,
            'aggregate_rating' => $metadata['aggregate_rating'] ?? null,
            'review_count' => $metadata['review_count'] ?? null,
            'beer_url' => $metadata['beer_url'] ?? $beer->url,
        ];

        // Convert aggregate rating to integer (multiply by 1000 to preserve 3 decimal places)
        $ratingValue = null;
        if (isset($metadata['aggregate_rating']) && is_numeric($metadata['aggregate_rating'])) {
            $ratingValue = (int) round($metadata['aggregate_rating'] * 1000);
        }

        // Create the block
        $block = $event->createBlock([
            'block_type' => 'beer_details',
            'title' => $beer->title,
            'content' => $metadata['description'] ?? null,
            'metadata' => $blockMetadata,
            'url' => $metadata['beer_url'] ?? $beer->url,
            'media_url' => null,
            'value' => $ratingValue,
            'value_multiplier' => 1000,
            'value_unit' => '/5',
            'time' => $event->time,
        ]);

        logger()->info('Created beer info block', [
            'block_id' => $block->id,
            'event_id' => $event->id,
            'beer_id' => $beer->id,
        ]);

        // Copy any media from the beer to the block
        if ($beer->hasMedia('downloaded_images')) {
            $media = $beer->getFirstMedia('downloaded_images');
            if ($media) {
                // Get the MD5 hash from custom properties
                $md5Hash = $media->getCustomProperty('md5_hash');

                if ($md5Hash) {
                    // Use MediaDownloadHelper to handle deduplication
                    $helper = app(\App\Services\Media\MediaDownloadHelper::class);
                    $helper->attachExistingMedia($media, $block, 'downloaded_images');
                } else {
                    // Fallback: copy without deduplication
                    $block->copyMedia($media->getPath())->toMediaCollection('downloaded_images');
                }
            }
        }
    }
}
