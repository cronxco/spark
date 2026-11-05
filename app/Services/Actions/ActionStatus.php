<?php

namespace App\Services\Actions;

enum ActionStatus: string
{
    /** Queued. The work has not finished, and may still fail. */
    case Accepted = 'accepted';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
