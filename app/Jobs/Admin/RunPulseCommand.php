<?php

namespace App\Jobs\Admin;

use App\Jobs\Migrations\MigrateOuraValueMappings;
use App\Models\Integration;
use App\Support\PulseCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Runs one whitelisted command for the Pulse page and keeps its output in cache.
 */
class RunPulseCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $key,
        public string $mode,
        public string $tenantId,
        public string $actorId,
    ) {}

    public static function cacheKey(string $tenantId, string $key): string
    {
        return "pulse:last:{$tenantId}:{$key}";
    }

    public function handle(): void
    {
        $definition = PulseCommands::find($this->key);
        $args = $definition[$this->mode] ?? null;

        if ($definition === null || ($this->mode !== 'preview' && $this->mode !== 'apply') || ! is_array($args)) {
            return;
        }

        $this->remember('running');
        activity('admin')->causedBy($this->actorId)->withProperties(['command' => $this->key, 'mode' => $this->mode, 'tenant' => $this->tenantId])->log("Pulse {$this->mode}: {$this->key}");

        try {
            if ($this->key === PulseCommands::OURA_JOB) {
                $integrations = Integration::where('service', 'oura')->where('user_id', $this->tenantId)->get();
                $integrations->each(fn (Integration $integration) => MigrateOuraValueMappings::dispatch($integration));
                $this->remember('done', 0, "Queued the migration for {$integrations->count()} Oura integration(s).");

                return;
            }

            $output = new BufferedOutput;
            $exit = Artisan::call($this->key, PulseCommands::resolveArguments($args, $this->tenantId), $output);
            $this->remember($exit === 0 ? 'done' : 'failed', $exit, $output->fetch());
        } catch (Throwable $e) {
            $this->remember('failed', 1, $e->getMessage());
        }
    }

    public function failed(Throwable $e): void
    {
        $this->remember('failed', 1, $e->getMessage());
    }

    public function remember(string $status, ?int $exit = null, string $output = ''): void
    {
        Cache::put(self::cacheKey($this->tenantId, $this->key), [
            'mode' => $this->mode,
            'status' => $status,
            'exit' => $exit,
            'output' => Str::limit($output, 20000, "\n… output truncated"),
            'at' => now()->toIso8601String(),
        ], now()->addDay());
    }
}
