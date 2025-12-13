<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class Code extends Icon
{
    public function svg(): string
    {
        // Use blade-svg to render the actual SVG icon
        return svg('fas-code', 'w-5 h-5')->toHtml();
    }
}
