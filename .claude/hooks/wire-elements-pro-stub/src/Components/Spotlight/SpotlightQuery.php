<?php

/** Sandbox stub for wire-elements/pro — see SpotlightServiceProvider.php in this directory for context. */

namespace WireElements\Pro\Components\Spotlight;

use Closure;

class SpotlightQuery
{
    protected function __construct(
        public ?string $mode,
        public ?string $token,
        public bool $default,
        public Closure $resolver,
    ) {}

    public static function forMode(string $mode, Closure $resolver): self
    {
        return new self($mode, null, false, $resolver);
    }

    public static function forToken(string $token, Closure $resolver): self
    {
        return new self(null, $token, false, $resolver);
    }

    public static function asDefault(Closure $resolver): self
    {
        return new self(null, null, true, $resolver);
    }
}
