<?php

namespace App\Services\Actions;

use RuntimeException;

class ConfirmationRequired extends RuntimeException
{
    public function __construct(public readonly ActionDefinition $action)
    {
        parent::__construct("{$action->title} needs confirmation.");
    }
}
