<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class PuzzlePiece extends Icon
{
    public function svg(): string
    {
        return '<i class="fa-solid fa-puzzle-piece"></i>';
    }
}
