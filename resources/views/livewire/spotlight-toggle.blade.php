<button
  type="button"
  class="btn btn-ghost btn-sm"
  title="Search"
  wire:click="$dispatch('spotlight.toggle')"
  data-hotkey="/">
  <x-icon name="fab.searchengin" class="w-5 h-5" />
  <span class="hidden lg:inline">Search</span>
</button>