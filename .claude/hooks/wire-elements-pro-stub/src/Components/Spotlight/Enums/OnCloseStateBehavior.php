<?php

/**
 * Sandbox stub for wire-elements/pro — see ../SpotlightServiceProvider.php
 * for context. Only the case referenced by config/wire-elements-pro.php is
 * declared; add more here if a future config value needs another case.
 */

namespace WireElements\Pro\Components\Spotlight\Enums;

enum OnCloseStateBehavior: string
{
    case KEEP_CURRENT_STATE = 'keep_current_state';
    case RESET_STATE = 'reset_state';
}
