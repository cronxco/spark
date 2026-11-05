<?php

namespace App\Icons\FontAwesome;

use WireElements\Pro\Icons\Icon;

class FontAwesomeIcon extends Icon
{
    public function __construct(
        protected string $iconName,
        protected string $style = 'solid'
    ) {}

    public static function make(string $iconName, string $style = 'solid'): self
    {
        return new self($iconName, $style);
    }

    public function svg(): string
    {
        // Use blade-svg to render the actual SVG icon
        return svg('fas-font-awesome', 'w-5 h-5')->toHtml();
    }
}
