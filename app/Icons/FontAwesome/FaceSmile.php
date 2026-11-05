<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class FaceSmile extends Icon
{
    public function svg(): string
    {
        // Use blade-svg to render the actual SVG icon
        return svg('fas-face-smile', 'w-5 h-5')->toHtml();
    }
}
