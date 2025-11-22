<div class="flex flex-col h-full p-6">
    <div class="text-center mb-6">
        <h2 class="text-2xl font-bold mb-2">Last Night</h2>
        <p class="text-base-content/70">Your overnight recovery</p>
    </div>

    <div class="flex-1 flex flex-col justify-center space-y-6">
        @if ($sleepScore || $readinessScore)
        <div class="grid grid-cols-2 gap-4">
            @if ($sleepScore)
            <div class="card bg-base-200">
                <div class="card-body items-center text-center p-4">
                    <x-icon name="fas-moon" class="w-8 h-8 text-info mb-2" />
                    <div class="text-3xl font-bold text-info">{{ $sleepScore }}</div>
                    <div class="text-sm text-base-content/70">Sleep Score</div>
                </div>
            </div>
            @endif

            @if ($readinessScore)
            <div class="card bg-base-200">
                <div class="card-body items-center text-center p-4">
                    <x-icon name="fas-bolt" class="w-8 h-8 text-success mb-2" />
                    <div class="text-3xl font-bold text-success">{{ $readinessScore }}</div>
                    <div class="text-sm text-base-content/70">Readiness</div>
                </div>
            </div>
            @endif
        </div>
        @endif

        @if ($totalSleep)
        <div class="card bg-base-200">
            <div class="card-body p-4">
                <h3 class="font-semibold mb-3 text-center">Sleep Breakdown</h3>
                <div class="space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-base-content/70">Total Sleep</span>
                        <span class="font-semibold">{{ format_duration($totalSleep) }}</span>
                    </div>
                    @if ($remSleep)
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-base-content/70">REM</span>
                        <span class="font-semibold">{{ format_duration($remSleep) }}</span>
                    </div>
                    @endif
                    @if ($deepSleep)
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-base-content/70">Deep</span>
                        <span class="font-semibold">{{ format_duration($deepSleep) }}</span>
                    </div>
                    @endif
                    @if ($lightSleep)
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-base-content/70">Light</span>
                        <span class="font-semibold">{{ format_duration($lightSleep) }}</span>
                    </div>
                    @endif
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
