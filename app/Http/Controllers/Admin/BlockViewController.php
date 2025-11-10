<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Block;

class BlockViewController extends Controller
{
    /**
     * Show the latest block of each type using appropriate block cards
     */
    public function index()
    {
        // Get distinct block types
        $blockTypes = Block::select('block_type')
            ->whereNotNull('block_type')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('block_type');

        // Get the latest block for each type
        $blocks = collect();
        foreach ($blockTypes as $blockType) {
            $latestBlock = Block::where('block_type', $blockType)
                ->whereNull('deleted_at')
                ->with(['event.integration'])
                ->orderBy('created_at', 'desc')
                ->first();

            if ($latestBlock) {
                $blocks->push($latestBlock);
            }
        }

        // Sort by block_type for consistent display
        $blocks = $blocks->sortBy('block_type')->values();

        return view('admin.block-view.index', [
            'blocks' => $blocks,
        ]);
    }
}
