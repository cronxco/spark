<?php

use App\Jobs\Flint\ProcessCoachingResponseJob;
use App\Models\EventObject;
use App\Services\PatternLearningService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public ?string $activeSectionId = null;
    public string $userResponse = '';
    public array $sessions = [];
    public bool $loading = false;

    public function mount(): void
    {
        $this->loadSessions();
    }

    public function loadSessions(): void
    {
        $user = Auth::user();
        $patternLearning = app(PatternLearningService::class);

        $activeSessions = $patternLearning->getActiveCoachingSessions($user);

        $this->sessions = $activeSessions->map(function ($session) {
            $metadata = $session->metadata ?? [];
            return [
                'id' => $session->id,
                'title' => $session->title,
                'anomaly_context' => $metadata['anomaly_context'] ?? [],
                'questions' => $metadata['ai_questions'] ?? [],
                'pattern_suggestions' => $metadata['pattern_suggestions'] ?? [],
                'created_at' => $session->time,
            ];
        })->toArray();
    }

    public function selectSession(string $sessionId): void
    {
        $this->activeSectionId = $this->activeSectionId === $sessionId ? null : $sessionId;
        $this->userResponse = '';
    }

    public function submitResponse(string $sessionId): void
    {
        if (empty(trim($this->userResponse))) {
            $this->error('Please provide a response before submitting.');
            return;
        }

        $this->loading = true;

        try {
            $session = EventObject::findOrFail($sessionId);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                abort(403);
            }

            // Dispatch the processing job
            ProcessCoachingResponseJob::dispatch(
                Auth::user(),
                $session,
                $this->userResponse
            );

            $this->success('Thank you! Your response is being processed.');
            $this->userResponse = '';
            $this->activeSectionId = null;

            // Reload sessions
            $this->loadSessions();
        } catch (\Exception $e) {
            $this->error('Failed to submit response. Please try again.');
        } finally {
            $this->loading = false;
        }
    }

    public function dismissSession(string $sessionId): void
    {
        try {
            $session = EventObject::findOrFail($sessionId);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                abort(403);
            }

            $patternLearning = app(PatternLearningService::class);
            $patternLearning->dismissCoachingSession($session);

            $this->success('Check-in dismissed.');
            $this->loadSessions();
        } catch (\Exception $e) {
            $this->error('Failed to dismiss check-in.');
        }
    }
}; ?>

<div class="space-y-4">
    @if (empty($sessions))
        <div class="card bg-base-200 shadow">
            <div class="card-body text-center py-8">
                <x-icon name="fas.heart-pulse" class="w-12 h-12 mx-auto text-success/50 mb-3" />
                <h3 class="text-lg font-semibold text-base-content/80">{{ __('All Caught Up!') }}</h3>
                <p class="text-sm text-base-content/60">
                    {{ __('No health check-ins pending. Your patterns are looking good.') }}
                </p>
            </div>
        </div>
    @else
        @foreach ($sessions as $session)
            <div class="card bg-base-200 shadow" wire:key="session-{{ $session['id'] }}">
                <div class="card-body p-4">
                    {{-- Header --}}
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-warning/20 rounded-lg">
                                <x-icon name="fas.heart-pulse" class="w-5 h-5 text-warning" />
                            </div>
                            <div>
                                <h3 class="font-semibold text-sm">{{ $session['title'] }}</h3>
                                <p class="text-xs text-base-content/60">
                                    {{ $session['created_at']->diffForHumans() }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                wire:click="selectSession('{{ $session['id'] }}')"
                                class="btn btn-ghost btn-sm"
                            >
                                <x-icon name="fas.chevron-{{ $activeSectionId === $session['id'] ? 'up' : 'down' }}" class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    {{-- Anomaly Context --}}
                    @if (!empty($session['anomaly_context']))
                        <div class="mt-3 p-3 bg-base-100 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-sm font-medium">
                                    {{ $session['anomaly_context']['metric_name'] ?? 'Health Metric' }}
                                </span>
                                <span class="badge badge-{{ $session['anomaly_context']['direction'] === 'up' ? 'warning' : 'info' }} badge-xs">
                                    {{ $session['anomaly_context']['direction'] === 'up' ? '↑' : '↓' }}
                                    {{ $session['anomaly_context']['deviation_percent'] ?? 0 }}%
                                </span>
                            </div>
                            <p class="text-xs text-base-content/70">
                                {{ $session['anomaly_context']['type_label'] ?? 'Anomaly detected' }} -
                                Current: {{ number_format($session['anomaly_context']['current_value'] ?? 0, 1) }}
                                (baseline: {{ number_format($session['anomaly_context']['baseline_value'] ?? 0, 1) }})
                            </p>
                        </div>
                    @endif

                    {{-- Expanded Content --}}
                    @if ($activeSectionId === $session['id'])
                        <div class="mt-4 space-y-4">
                            {{-- Pattern Suggestions --}}
                            @if (!empty($session['pattern_suggestions']))
                                <div class="p-3 bg-info/10 rounded-lg">
                                    <h4 class="text-xs font-medium text-info mb-2 flex items-center gap-1">
                                        <x-icon name="fas.brain" class="w-3 h-3" />
                                        {{ __('Previously Learned Patterns') }}
                                    </h4>
                                    <ul class="space-y-1">
                                        @foreach (array_slice($session['pattern_suggestions'], 0, 2) as $suggestion)
                                            <li class="text-xs text-base-content/80">
                                                • {{ $suggestion['suggestion'] }}
                                                <span class="text-base-content/50">({{ $suggestion['confirmations'] }}x confirmed)</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- Questions --}}
                            <div class="space-y-3">
                                <h4 class="text-xs font-medium text-base-content/60 uppercase tracking-wide">
                                    {{ __('Reflection Questions') }}
                                </h4>
                                @foreach ($session['questions'] as $index => $question)
                                    <div class="flex items-start gap-2 text-sm">
                                        <span class="text-primary font-medium">{{ $index + 1 }}.</span>
                                        <span>{{ $question }}</span>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Response Input --}}
                            <div class="space-y-3">
                                <textarea
                                    wire:model="userResponse"
                                    class="textarea textarea-bordered w-full"
                                    rows="3"
                                    placeholder="{{ __('Share your thoughts...') }}"
                                ></textarea>

                                <div class="flex justify-between">
                                    <button
                                        wire:click="dismissSession('{{ $session['id'] }}')"
                                        class="btn btn-ghost btn-sm"
                                    >
                                        {{ __('Dismiss') }}
                                    </button>
                                    <button
                                        wire:click="submitResponse('{{ $session['id'] }}')"
                                        class="btn btn-primary btn-sm"
                                        wire:loading.attr="disabled"
                                    >
                                        <span wire:loading.remove>{{ __('Submit') }}</span>
                                        <span wire:loading>{{ __('Processing...') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
</div>
