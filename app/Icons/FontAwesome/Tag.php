<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class Tag extends Icon
{
    public function svg(): string
    {
        return '<i class="fa-solid fa-tag"></i>';
    }
}
