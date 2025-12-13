<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class Mountain extends Icon
{
    public function svg(): string
    {
        // Use blade-svg to render the actual SVG icon
        return svg('fas-mountain', 'w-5 h-5')->toHtml();
    }
}
