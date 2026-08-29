<?php

/** Sandbox stub for wire-elements/pro — see SpotlightServiceProvider.php in this directory for context. */

namespace WireElements\Pro\Components\Spotlight;

class SpotlightMode
{
    protected string $character = '';

    protected function __construct(
        public string $key,
        public string $label,
    ) {}

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public function setCharacter(string $character): self
    {
        $this->character = $character;

        return $this;
    }
}
