<?php

namespace App\Jobs\Data\GoogleCalendar;

use App\Integrations\GoogleCalendar\GoogleCalendarPlugin;
use App\Jobs\Base\BaseProcessingJob;

class GoogleCalendarEventsData extends BaseProcessingJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    public function getRawData()
    {
        return $this->rawData;
    }

    protected function getServiceName(): string
    {
        return 'google-calendar';
    }

    protected function getJobType(): string
    {
        return 'events';
    }

    protected function process(): void
    {
        $eventData = $this->rawData;
        $plugin = new GoogleCalendarPlugin;

        $plugin->processEventData($this->integration, $eventData);
    }
}
