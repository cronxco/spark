<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class AppBrand extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return <<<'HTML'
                <a href="/" wire:navigate>
                    <!-- Hidden when collapsed -->
                    <div class="flex items-center gap-2 block lg:hidden align-middle">
                        <div class="flex items-center gap-2 w-fit">
                            <x-app-logo class="w-20 -mb-1"/>
                        </div>
                    </div>

                    <!-- Display when collapsed -->
                    <div class="flex items-center gap-2 hidden lg:block lg:inline-block align-middle">
                        <x-app-logo class="w-26 mt-1 -mb-1"/>
                    </div>
                </a>
            HTML;
    }
}
