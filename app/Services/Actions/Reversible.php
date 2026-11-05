<?php

namespace App\Services\Actions;

use App\Models\User;

/**
 * An action handler whose effect can be undone. The outcome's `undo` payload
 * is stored on the receipt and handed back here, so the undo works from the
 * receipt alone.
 */
interface Reversible
{
    /** @param  array<string, mixed>  $undo */
    public function undo(User $user, array $undo): void;
}
