<?php

namespace App\Jobs\IntegrationGroup;

use App\Models\Block;

class DeleteBlockJob extends BaseIndividualDeletionJob
{
    public function handle(): void
    {
        $block = Block::find($this->recordId);

        if ($block) {
            $block->forceDelete();
            $this->logDeletion('block', $this->recordId);
        }
    }
}
