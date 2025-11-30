<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class Comment extends Icon
{
    public function svg(): string
    {
        return '<i class="fa-solid fa-comment"></i>';
    }
}
