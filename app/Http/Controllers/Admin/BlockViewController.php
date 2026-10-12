<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Support\AdminTenant;

class BlockViewController extends Controller
{
    /**
     * Show the latest block of each type using appropriate block cards
     */
    public function index()
    {
        $tenantId = AdminTenant::id();

        // Latest block of each type for the viewed tenant, in one query
        $latestIds = Block::query()
            ->whereNotNull('block_type')
            ->whereHas('event.integration', fn ($q) => $q->where('user_id', $tenantId))
            ->selectRaw('DISTINCT ON (blocks.block_type) blocks.id')
            ->orderBy('blocks.block_type')
            ->orderByDesc('blocks.created_at')
            ->pluck('id');

        $blocks = Block::with(['event.integration'])
            ->whereIn('id', $latestIds)
            ->orderBy('block_type')
            ->get();

        return view('admin.block-view.index', [
            'blocks' => $blocks,
        ]);
    }
}
