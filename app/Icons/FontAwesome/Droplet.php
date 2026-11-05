<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class Droplet extends Icon
{
    public function svg(): string
    {
        // Use blade-svg to render the actual SVG icon
        return svg('fas-droplet', 'w-5 h-5')->toHtml();
    }
}
