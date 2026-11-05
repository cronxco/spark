<?php

namespace App\Jobs\IntegrationGroup;

use App\Models\EventObject;

class DeleteEventObjectJob extends BaseIndividualDeletionJob
{
    public function handle(): void
    {
        $object = EventObject::find($this->recordId);

        if ($object) {
            $object->forceDelete();
            $this->logDeletion('event_object', $this->recordId);
        }
    }
}
