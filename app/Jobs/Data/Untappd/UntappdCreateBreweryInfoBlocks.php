<?php

namespace App\Jobs\Data\Untappd;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;

class UntappdCreateBreweryInfoBlocks extends BaseProcessingJob
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
        return 'create_brewery_info_blocks';
    }

    protected function process(): void
    {
        $breweryId = $this->rawData['brewery_id'] ?? null;

        if (! $breweryId) {
            logger()->warning('Missing brewery_id for brewery info block creation');

            return;
        }

        $brewery = EventObject::find($breweryId);

        if (! $brewery) {
            logger()->warning('Brewery EventObject not found', ['brewery_id' => $breweryId]);

            return;
        }

        logger()->info('Creating brewery info blocks', [
            'brewery_id' => $brewery->id,
            'brewery_title' => $brewery->title,
        ]);

        // Find all events for beers from this brewery that don't already have a brewery_details block
        // We need to find events where the beer's brewery matches this brewery
        $events = Event::where('user_id', $this->integration->user_id)
            ->where('service', 'untappd')
            ->where('action', 'drank')
            ->whereHas('target', function ($query) use ($brewery) {
                // Filter to beers that have this brewery name in their metadata
                $query->where('type', 'untappd_beer')
                    ->where('metadata->brewery_name', $brewery->title);
            })
            ->whereDoesntHave('blocks', function ($query) {
                $query->where('block_type', 'brewery_details');
            })
            ->get();

        logger()->info('Found events needing brewery info blocks', [
            'brewery_id' => $brewery->id,
            'event_count' => $events->count(),
        ]);

        foreach ($events as $event) {
            $this->createBreweryInfoBlock($event, $brewery);
        }
    }

    private function createBreweryInfoBlock(Event $event, EventObject $brewery): void
    {
        $metadata = $brewery->metadata ?? [];

        // Build block metadata
        $blockMetadata = [
            'description' => $metadata['description'] ?? null,
            'address' => $metadata['address'] ?? null,
            'street_address' => $metadata['street_address'] ?? null,
            'locality' => $metadata['locality'] ?? null,
            'region' => $metadata['region'] ?? null,
            'aggregate_rating' => $metadata['aggregate_rating'] ?? null,
            'review_count' => $metadata['review_count'] ?? null,
            'brewery_url' => $metadata['brewery_url'] ?? $brewery->url,
        ];

        // Create the block
        $block = $event->createBlock([
            'block_type' => 'brewery_details',
            'title' => $brewery->title,
            'content' => $metadata['description'] ?? null,
            'metadata' => $blockMetadata,
            'url' => $metadata['brewery_url'] ?? $brewery->url,
            'media_url' => null,
            'value' => $metadata['aggregate_rating'] ?? null,
            'value_multiplier' => 1,
            'value_unit' => '/5',
            'time' => $event->time,
        ]);

        logger()->info('Created brewery info block', [
            'block_id' => $block->id,
            'event_id' => $event->id,
            'brewery_id' => $brewery->id,
        ]);

        // Copy any media from the brewery to the block
        if ($brewery->hasMedia('downloaded_images')) {
            $media = $brewery->getFirstMedia('downloaded_images');
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
