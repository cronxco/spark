<?php

namespace App\Jobs\IntegrationGroup;

use App\Models\Event;

class DeleteEventJob extends BaseIndividualDeletionJob
{
    public function handle(): void
    {
        $event = Event::find($this->recordId);

        if ($event) {
            $event->forceDelete();
            $this->logDeletion('event', $this->recordId);
        }
    }
}
