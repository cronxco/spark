<?php

namespace App\Services\Actions;

enum ActionKind: string
{
    /** Opens a screen. The client navigates; the server never runs it. */
    case Navigation = 'navigation';

    /** Explicit, idempotent, or queue-only: repeating it changes nothing new. */
    case Safe = 'safe';

    /** Changes the user's data. */
    case Data = 'data';

    /** Opens a modal or panel on the page the user is already on. Web only; the page runs it. */
    case Interface = 'interface';
}
