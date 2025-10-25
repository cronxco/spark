<div class="flex flex-col h-full p-6">
    <div class="text-center mb-8">
        <h2 class="text-2xl font-bold mb-2">Afternoon Check-in</h2>
        <p class="text-base-content/70">How are you feeling this afternoon?</p>
    </div>

    <div class="flex-1 flex items-center justify-center">
        <div class="w-full max-w-md">
            <livewire:daily-checkin :date="$date" :key="'card-afternoon-checkin-' . $date" :hide-selector="true" />
        </div>
    </div>

    <div class="text-center text-sm text-base-content/60 mt-4">
        Rate your physical and mental energy
    </div>
</div>