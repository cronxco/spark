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
        // Return Font Awesome icon as HTML
        return <<<HTML
        <i class="fa-{$this->style} fa-{$this->iconName}"></i>
        HTML;
    }
}
