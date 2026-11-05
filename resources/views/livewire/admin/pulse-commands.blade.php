<?php

use App\Jobs\Admin\RunPulseCommand;
use App\Support\AdminTenant;
use App\Support\PulseCommands;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component
{
    use Toast;

    /** @var array<string, string> typed confirmations keyed by command key */
    public array $typed = [];

    public function run(string $key, string $mode): void
    {
        $definition = PulseCommands::find($key);

        if ($definition === null || ! in_array($mode, ['preview', 'apply'], true) || ! is_array($definition[$mode])) {
            $this->error('That command cannot be run from here.');

            return;
        }

        if ($mode === 'apply' && $definition['sensitive'] && trim($this->typed[$key] ?? '') !== $key) {
            $this->error('Type the command name to confirm.');

            return;
        }

        if (in_array($this->status($key)['status'] ?? null, ['queued', 'running'], true)) {
            $this->error('That command is already running.');

            return;
        }

        $tenantId = (string) (AdminTenant::id() ?? Auth::id());

        Cache::put(RunPulseCommand::cacheKey($tenantId, $key), [
            'mode' => $mode, 'status' => 'queued', 'exit' => null, 'output' => '', 'at' => now()->toIso8601String(),
        ], now()->addDay());

        RunPulseCommand::dispatch($key, $mode, $tenantId, (string) Auth::id());

        unset($this->typed[$key]);
        $this->success($mode === 'preview' ? 'Preview queued.' : 'Command queued.');
    }

    /**
     * @return array{mode: string, status: string, exit: ?int, output: string, at: string}|null
     */
    public function status(string $key): ?array
    {
        return Cache::get(RunPulseCommand::cacheKey((string) (AdminTenant::id() ?? Auth::id()), $key));
    }

    public function anyActive(): bool
    {
        foreach (array_keys(PulseCommands::all()) as $key) {
            if (in_array($this->status($key)['status'] ?? null, ['queued', 'running'], true)) {
                return true;
            }
        }

        return false;
    }
};

?>

<div @if ($this->anyActive()) wire:poll.3s @endif class="space-y-3">
    <p class="text-sm text-base-content/70">
        Preview shows what a command would change. Commands run on the queue; output appears here.
    </p>

    @foreach (PulseCommands::all() as $key => $command)
        @php($status = $this->status($key))
        <div class="card bg-base-200" wire:key="pulse-{{ $key }}">
            <div class="card-body p-4 gap-2">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="font-medium">{{ $command['label'] }}</div>
                        <div class="text-xs text-base-content/60 font-mono">{{ $key }}</div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge badge-ghost badge-sm">{{ $command['scope'] }}</span>
                        @if ($command['sensitive'])
                            <span class="badge badge-warning badge-sm">sensitive</span>
                        @endif
                        @if ($status)
                            <span class="badge badge-sm {{ match ($status['status']) { 'done' => 'badge-success', 'failed' => 'badge-error', default => 'badge-info' } }}">{{ $status['mode'] }} {{ $status['status'] }}</span>
                        @endif
                    </div>
                </div>

                <p class="text-sm text-base-content/70">{{ $command['description'] }}</p>

                @if ($command['note'])
                    <p class="text-xs text-base-content/60 font-mono">{{ $command['note'] }}</p>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    @if (is_array($command['preview']))
                        <button class="btn btn-sm btn-outline" wire:click="run('{{ $key }}', 'preview')" wire:loading.attr="disabled">Preview</button>
                    @endif

                    @if (is_array($command['apply']))
                        @if ($command['sensitive'])
                            <input type="text" class="input input-bordered input-sm w-64 font-mono" placeholder="Type {{ $key }}"
                                wire:model.live.debounce.200ms="typed.{{ $key }}" />
                            <button class="btn btn-sm btn-error" wire:click="run('{{ $key }}', 'apply')"
                                @disabled(trim($typed[$key] ?? '') !== $key)>Run</button>
                        @else
                            <button class="btn btn-sm btn-error btn-outline" wire:click="run('{{ $key }}', 'apply')"
                                wire:confirm="Run {{ $command['label'] }}? {{ $command['scope'] === 'all users' ? 'This changes data for every user.' : 'This changes your data.' }}">Run</button>
                        @endif
                    @endif
                </div>

                @if ($status && $status['output'] !== '')
                    <pre class="bg-base-300 rounded p-3 text-xs overflow-x-auto max-h-64">{{ $status['output'] }}</pre>
                @endif
            </div>
        </div>
    @endforeach
</div>
