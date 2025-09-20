<?php

namespace App\Jobs\Data\Spotify;

use App\Integrations\Spotify\SpotifyPlugin;
use App\Jobs\Base\BaseProcessingJob;

class SpotifyListeningData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'spotify';
    }

    protected function getJobType(): string
    {
        return 'listening';
    }

    protected function process(): void
    {
        $listeningData = $this->rawData;
        $plugin = new SpotifyPlugin;

        $plugin->processListeningData($this->integration, $listeningData);
    }
}
