<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;

class GenerateMissingResponsiveImages extends Command
{
    protected $signature = 'media:fix-responsive-images';

    protected $description = 'Generate responsive images for any media items missing them';

    public function handle()
    {
        $generator = app(ResponsiveImageGenerator::class);

        $this->info('Scanning for media missing responsive images...');

        $count = 0;

        Media::chunk(200, function ($medias) use (&$count, $generator) {
            foreach ($medias as $media) {
                if (empty($media->responsive_images)) {
                    $this->info("Generating responsive images for media {$media->uuid}");

                    $generator->generateResponsiveImages($media);
                    $media->refresh();
                    $count++;
                }
            }
        });

        $this->info("Done. Fixed {$count} media items.");
    }
}
