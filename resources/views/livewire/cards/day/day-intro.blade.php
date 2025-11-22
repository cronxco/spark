<div class="flex flex-col items-center justify-center h-full text-center p-8">
    <x-icon name="fas-sun" class="w-24 h-24 text-primary mb-6" />

    <h1 class="text-4xl font-bold mb-2">
        Good Afternoon
    </h1>

    @if ($userName)
    <p class="text-4xl text-base-content/70 mb-8">
        {{ $userName }}
    </p>
    @endif

    <p class="text-base-content/60">
        How's your day going?
    </p>
</div>
