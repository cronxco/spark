<?php

namespace Tests\Feature\Money;

use App\Livewire\Money\BufferPot;
use App\Livewire\Money\Now;
use App\Livewire\Money\Worth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LazyLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        SupportLazyLoading::$disableWhileTesting = false;
    }

    #[Test]
    public function a_second_lazy_load_call_on_a_loaded_money_view_is_harmless(): void
    {
        $user = User::factory()->create();

        foreach ([Now::class, BufferPot::class, Worth::class] as $component) {
            $this->actingAs($user);
            preg_match("/__lazyLoad\\('([^']+)'\\)/", html_entity_decode(Livewire::mount($component)), $matches);
            $this->assertNotEmpty($matches, $component . ' should render a lazy placeholder.');

            $test = Livewire::test($component)->call('__lazyLoad', $matches[1])->assertHasNoErrors();
            $test->call('__lazyLoad', $matches[1])->assertHasNoErrors()->assertOk();
        }
    }
}
