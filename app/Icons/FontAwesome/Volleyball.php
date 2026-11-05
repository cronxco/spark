<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class Volleyball extends Icon
{
    public function svg(): string
    {
        // Use blade-svg to render the actual SVG icon
        return svg('fas-volleyball', 'w-5 h-5')->toHtml();
    }
}
