<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class BatteryFull extends Icon
{
    public function svg(): string
    {
        return '<i class="fa-solid fa-battery-full"></i>';
    }
}
