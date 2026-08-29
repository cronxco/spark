<?php

/**
 * Sandbox stub for wire-elements/pro — see SpotlightServiceProvider.php in
 * this directory for context. Real SpotlightResult::make() calls only ever
 * happen inside query-resolver closures, which this stub never invokes, so
 * a permissive __call() fluent no-op covers every builder method (setTitle,
 * setIcon, etc.) without needing to enumerate the real API.
 */

namespace WireElements\Pro\Components\Spotlight;

class SpotlightResult
{
    public static function make(): self
    {
        return new self;
    }

    public function __call(string $name, array $arguments): self
    {
        return $this;
    }
}
