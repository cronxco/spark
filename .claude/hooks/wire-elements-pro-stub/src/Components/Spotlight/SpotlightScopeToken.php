<?php

/** Sandbox stub for wire-elements/pro — see SpotlightServiceProvider.php in this directory for context. */

namespace WireElements\Pro\Components\Spotlight;

use Closure;

class SpotlightScopeToken
{
    protected array $parameters = [];

    protected string $text = '';

    protected function __construct(
        public string $key,
        public Closure $resolver,
    ) {}

    public static function make(string $key, Closure $resolver): self
    {
        return new self($key, $resolver);
    }

    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;

        return $this;
    }

    public function setText(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function getParameter(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }
}
