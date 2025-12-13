<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class File extends Icon
{
    public function svg(): string
    {
        // Use blade-svg to render the actual SVG icon
        return svg('fas-file', 'w-5 h-5')->toHtml();
    }
}
