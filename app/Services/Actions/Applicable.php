<?php

namespace App\Services\Actions;

use App\Models\User;

/**
 * An action handler that can say whether its action makes sense for a target,
 * so a page offers Pause only on a running integration and nothing at all for
 * a record the user does not own.
 */
interface Applicable
{
    /** @param  array<string, mixed>  $target  the validated target with the definition's fixed params merged in */
    public function applies(User $user, array $target): bool;
}
