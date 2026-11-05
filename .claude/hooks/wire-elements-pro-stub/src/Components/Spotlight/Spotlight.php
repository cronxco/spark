<?php

/**
 * Sandbox stub for wire-elements/pro — see SpotlightServiceProvider.php in
 * this directory for context. Reproduces just enough of the real Spotlight
 * facade's registration API for App\Providers\SpotlightServiceProvider::boot()
 * to run without error; none of the registered closures are ever invoked
 * here, so this never needs to reproduce actual query/render behaviour.
 */

namespace WireElements\Pro\Components\Spotlight;

use Closure;

class Spotlight
{
    protected static array $actions = [];

    protected static array $modes = [];

    protected static array $groups = [];

    protected static array $tokens = [];

    protected static array $scopes = [];

    protected static array $queries = [];

    protected static array $tips = [];

    public static function setup(Closure $callback): void
    {
        $callback();
    }

    public static function registerAction(string $key, string $actionClass): void
    {
        static::$actions[$key] = $actionClass;
    }

    public static function registerModes(SpotlightMode ...$modes): void
    {
        array_push(static::$modes, ...$modes);
    }

    public static function registerGroup(string $key, string $label, int $order = 0): void
    {
        static::$groups[$key] = ['label' => $label, 'order' => $order];
    }

    public static function registerTokens(SpotlightScopeToken ...$tokens): void
    {
        array_push(static::$tokens, ...$tokens);
    }

    public static function registerScopes(SpotlightScope ...$scopes): void
    {
        array_push(static::$scopes, ...$scopes);
    }

    public static function registerQueries(SpotlightQuery ...$queries): void
    {
        array_push(static::$queries, ...$queries);
    }

    public static function registerTips(string ...$tips): void
    {
        array_push(static::$tips, ...$tips);
    }

    public function dispatch(string $event, array $payload = []): void
    {
        //
    }
}
