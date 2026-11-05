<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class Rotate extends Icon
{
    public function svg(): string
    {
        // Use blade-svg to render the actual SVG icon
        return svg('fas-rotate', 'w-5 h-5')->toHtml();
    }
}
