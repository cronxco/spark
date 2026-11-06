<?php

namespace Tests\Unit\Support;

use App\Support\BlockDetails;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BlockDetailsTest extends TestCase
{
    #[Test]
    public function every_type_with_details_has_a_web_card(): void
    {
        $layouts = dirname(__DIR__, 3) . '/resources/views/blocks/types';

        foreach (BlockDetails::types() as $type) {
            $this->assertFileExists("{$layouts}/{$type}.blade.php", "{$type} has details but no card layout");
        }
    }
}
