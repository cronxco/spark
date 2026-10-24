<?php

namespace App\Services\Actions;

use App\Models\User;

interface ActionHandler
{
    /**
     * Runs the action for the user. `$target` is already validated and has the
     * definition's fixed params merged in. Throw {@see ActionFailed} when the
     * action cannot be carried out.
     *
     * @param  array<string, mixed>  $target
     */
    public function handle(User $user, array $target): ActionOutcome;
}
