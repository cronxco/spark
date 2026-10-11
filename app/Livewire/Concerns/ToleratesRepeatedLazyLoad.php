<?php

namespace App\Livewire\Concerns;

/**
 * Livewire resumes a lazy component by calling `__lazyLoad`, and only handles that call while the
 * component is still a placeholder. A second call, sent when a hidden tab becomes visible again
 * before the first load has returned, reaches a loaded component and would throw
 * MethodNotFoundException. The call is a no-op once the component is loaded.
 */
trait ToleratesRepeatedLazyLoad
{
    public function __lazyLoad(string $encoded = ''): void {}
}
