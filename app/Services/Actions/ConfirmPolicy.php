<?php

namespace App\Services\Actions;

enum ConfirmPolicy: string
{
    /** Runs directly and needs no Undo: navigation and idempotent actions. */
    case None = 'none';

    /** Cheap and reversible: runs directly, and the receipt offers Undo. */
    case Undo = 'undo';

    /** Costly or irreversible: the client asks first and re-sends with `confirmed`. */
    case Confirm = 'confirm';
}
