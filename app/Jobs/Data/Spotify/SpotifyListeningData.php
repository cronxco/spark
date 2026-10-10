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

        // Retry transient failures, but on the last attempt skip tracks that keep
        // failing so the listening cursor can still advance.
        $plugin->processListeningData($this->integration, $listeningData, $this->attempts() >= $this->tries);
    }
}
