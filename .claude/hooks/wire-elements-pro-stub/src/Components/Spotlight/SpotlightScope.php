<?php

/** Sandbox stub for wire-elements/pro — see SpotlightServiceProvider.php in this directory for context. */

namespace WireElements\Pro\Components\Spotlight;

use Closure;

class SpotlightScope
{
    protected function __construct(
        public string $route,
        public Closure $resolver,
    ) {}

    public static function forRoute(string $route, Closure $resolver): self
    {
        return new self($route, $resolver);
    }

    public function applyToken(string $token, array $parameters = []): self
    {
        return $this;
    }
}
