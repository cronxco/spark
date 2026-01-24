<?php

namespace App\Spotlight\Scopes;

use WireElements\Pro\Components\Spotlight\SpotlightScope;

class BlockDetailScope
{
    public static function make(): SpotlightScope
    {
        return SpotlightScope::forRoute('blocks.show', function ($scope, $request) {
            // Try multiple ways to get the block model
            $block = $request->route('block') ?? $request->route()->parameter('block');
            if ($block) {
                $blockTitle = $block->title ?? ucfirst(str_replace('_', ' ', $block->block_type ?? 'Block'));

                $scope->applyToken('block', [
                    'block' => [
                        'id' => $block->id,
                        'display' => $blockTitle.($block->block_type ? ' • '.ucfirst(str_replace('_', ' ', $block->block_type)) : ''),
                        'title' => $blockTitle,
                        'block_type' => $block->block_type,
                    ],
                ]);
            }
        });
    }
}
