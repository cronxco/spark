<div class="flex flex-col items-center justify-center h-full text-center p-8">
    <x-icon name="o-sun" class="w-24 h-24 text-warning mb-6" />

    <h1 class="text-4xl font-bold mb-2">
        {{ $greeting }}
    </h1>

    @if ($userName)
    <p class="text-4xl text-base-content/70 mb-8">
        {{ $userName }}
    </p>
    @endif

    <p class="text-base-content/60">
        Let's start your day off right
    </p>
</div>
