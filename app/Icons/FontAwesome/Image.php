<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class Image extends Icon
{
    public function svg(): string
    {
        return '<i class="fa-solid fa-image"></i>';
    }
}
