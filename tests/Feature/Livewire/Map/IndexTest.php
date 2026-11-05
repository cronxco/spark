<?php

namespace Tests\Feature\Livewire\Map;

use App\Livewire\Map\Index;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IndexTest extends TestCase
{
    #[Test]
    public function renders_successfully()
    {
        Livewire::test(Index::class)
            ->assertStatus(200);
    }
}
