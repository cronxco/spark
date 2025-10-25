<?php

namespace App\Jobs\OAuth\GoogleCalendar;

use App\Integrations\GoogleCalendar\GoogleCalendarPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GoogleCalendar\GoogleCalendarEventsData;
use Illuminate\Support\Facades\Log;

class GoogleCalendarEventsPull extends BaseFetchJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'google-calendar';
    }

    protected function getJobType(): string
    {
        return 'events';
    }

    protected function fetchData(): array
    {
        $plugin = new GoogleCalendarPlugin;

        return $plugin->pullEventData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['events'])) {
            Log::info('Google Calendar: No events to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch event processing job
        GoogleCalendarEventsData::dispatch($this->integration, $rawData);
    }
}
