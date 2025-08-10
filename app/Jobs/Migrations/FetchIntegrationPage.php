<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class FetchIntegrationPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    protected Integration $integration;
    protected array $context;

    public function __construct(Integration $integration, array $context)
    {
        $this->integration = $integration;
        $this->context = $context;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        // Check timebox
        $timeboxIso = $this->context['timebox_until'] ?? null;
        if ($timeboxIso && Carbon::now()->greaterThanOrEqualTo(Carbon::parse($timeboxIso))) {
            Log::info('Migration timebox exceeded, stopping', [
                'integration_id' => $this->integration->id,
                'service' => $this->context['service'] ?? null,
            ]);
            return;
        }

        $service = $this->context['service'] ?? $this->integration->service;
        if ($service === 'oura') {
            $this->fetchOura();
            return;
        }
        if ($service === 'spotify') {
            $this->fetchSpotify();
            return;
        }
        if ($service === 'github') {
            $this->fetchGitHub();
            return;
        }
    }

    protected function fetchOura(): void
    {
        $type = $this->context['instance_type'] ?? ($this->integration->instance_type ?: 'activity');
        $cursor = $this->context['cursor'] ?? [];

        // Use plugin helper to fetch with headers/status
        $pluginClass = PluginRegistry::getPlugin('oura');
        $plugin = new $pluginClass();
        $resp = $plugin->fetchWindowWithMeta($this->integration, $type, $cursor);

        // Rate limit handling
        if (!$resp['ok']) {
            $status = (int) ($resp['status'] ?? 0);
            if ($status === 429) {
                $headers = is_array($resp['headers'] ?? null) ? ($resp['headers'] ?? []) : [];
                $headersLower = [];
                foreach ($headers as $name => $values) {
                    $headersLower[strtolower((string) $name)] = $values;
                }
                $retryAfterHeader = $headersLower['retry-after'] ?? null;
                $retryAfterValue = is_array($retryAfterHeader) ? ($retryAfterHeader[0] ?? null) : $retryAfterHeader;
                $retryAfter = (int) ($retryAfterValue ?? 30);
                static::dispatch($this->integration, $this->context)
                    ->onConnection('redis')->onQueue('migration')->delay(now()->addSeconds(max(5, $retryAfter)));
                return;
            }
            // Backoff and retry via job retries
            throw new \RuntimeException('Oura fetch failed with status ' . $status);
        }

        $items = $resp['items'] ?? [];
        if (empty($items)) {
            // No more data; stop chain
            return;
        }

        // Build next cursor by shifting window back
        $windowDays = (int) ($this->context['window_days'] ?? 30);
        $nextContext = $this->context;
        if ($type === 'heartrate') {
            $start = Carbon::parse($cursor['start_datetime']);
            $end = Carbon::parse($cursor['end_datetime']);
            $nextEnd = $start->copy()->subSecond();
            $nextStart = $nextEnd->copy()->subDays($windowDays - 1);
            $nextContext['cursor'] = [
                'start_datetime' => $nextStart->toIso8601String(),
                'end_datetime' => $nextEnd->toIso8601String(),
            ];
        } else {
            $start = Carbon::parse($cursor['start_date']);
            $end = Carbon::parse($cursor['end_date']);
            $nextEnd = $start->copy()->subDay();
            $nextStart = $nextEnd->copy()->subDays($windowDays - 1);
            $nextContext['cursor'] = [
                'start_date' => $nextStart->toDateString(),
                'end_date' => $nextEnd->toDateString(),
            ];
        }

        // Chain processing then next fetch to keep strict order
        Bus::chain([
            new ProcessIntegrationPage($this->integration, $items, $this->context),
            new FetchIntegrationPage($this->integration, $nextContext),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function fetchSpotify(): void
    {
        $cursor = $this->context['cursor'] ?? [];
        $beforeMs = (int) ($cursor['before_ms'] ?? (int) round(microtime(true) * 1000));
        $group = $this->integration->group;
        $token = $group?->access_token ?? $this->integration->access_token;
        if ($group) {
            $pluginClass = PluginRegistry::getPlugin('spotify');
            (new $pluginClass())->ensureFreshToken($group);
            $token = $group->access_token ?? $token;
        }
        $url = 'https://api.spotify.com/v1/me/player/recently-played';
        $resp = \Illuminate\Support\Facades\Http::withToken($token)
            ->get($url, ['limit' => 50, 'before' => $beforeMs]);

        if ($resp->status() === 429) {
            $retryAfter = (int) ($resp->header('Retry-After') ?? 30);
            static::dispatch($this->integration, $this->context)
                ->onConnection('redis')->onQueue('migration')->delay(now()->addSeconds(max(5, $retryAfter)));
            return;
        }
        if (!$resp->successful()) {
            throw new \RuntimeException('Spotify fetch failed: ' . $resp->status());
        }
        $json = $resp->json();
        $items = $json['items'] ?? [];
        if (empty($items)) {
            return;
        }

        // Prefer API-provided cursors; fall back to min played_at
        $nextBefore = null;
        $cursors = $json['cursors'] ?? [];
        if (!empty($cursors) && isset($cursors['before'])) {
            $nextBefore = (int) $cursors['before'];
        }
        if ($nextBefore === null) {
            $minTsMs = $beforeMs;
            foreach ($items as $it) {
                $playedAt = $it['played_at'] ?? null;
                if ($playedAt) {
                    $ts = (int) (Carbon::parse($playedAt)->getTimestampMs());
                    if ($ts < $minTsMs) {
                        $minTsMs = $ts;
                    }
                }
            }
            $nextBefore = $minTsMs - 1;
        }

        // Guard against non-progressing cursor
        if ($nextBefore >= $beforeMs) {
            return;
        }
        $nextContext = $this->context;
        $nextContext['cursor']['before_ms'] = $nextBefore;

        Bus::chain([
            new ProcessIntegrationPage($this->integration, $items, $this->context),
            new FetchIntegrationPage($this->integration, $nextContext),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function fetchGitHub(): void
    {
        $config = $this->integration->configuration ?? [];
        $repositories = $config['repositories'] ?? [];
        if (is_string($repositories)) {
            $decoded = json_decode($repositories, true);
            $repositories = is_array($decoded) ? $decoded : (preg_split('/[\n,]/', $repositories) ?: []);
            $repositories = array_values(array_filter(array_map('trim', $repositories)));
        }
        if (empty($repositories)) {
            return; // nothing to do
        }
        $cursor = $this->context['cursor'] ?? ['repo_index' => 0, 'page' => 1];
        $repoIndex = (int) ($cursor['repo_index'] ?? 0);
        $page = (int) ($cursor['page'] ?? 1);
        if (!isset($repositories[$repoIndex])) {
            return; // finished all repos
        }
        $repo = $repositories[$repoIndex];
        $group = $this->integration->group;
        $token = $group?->access_token ?? $this->integration->access_token;
        $url = "https://api.github.com/repos/{$repo}/events";
        $resp = \Illuminate\Support\Facades\Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => config('app.name', 'SparkApp'),
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->get($url, ['per_page' => 100, 'page' => $page]);

        if ($resp->status() === 429 || $resp->status() === 403) {
            // Prefer GitHub's X-RateLimit-Reset (epoch seconds) when available
            $resetHeader = $resp->header('X-RateLimit-Reset');
            $delaySeconds = null;
            if ($resetHeader !== null) {
                $resetAt = (int) $resetHeader; // epoch seconds
                if ($resetAt > 0) {
                    $nowTs = now()->getTimestamp();
                    $diff = $resetAt - $nowTs;
                    if ($diff > 0) {
                        $delaySeconds = $diff;
                    }
                }
            }

            if ($delaySeconds === null) {
                $retryAfter = (int) ($resp->header('Retry-After') ?? 60);
                $delaySeconds = max(30, $retryAfter);
            }

            static::dispatch($this->integration, $this->context)
                ->onConnection('redis')->onQueue('migration')->delay(now()->addSeconds($delaySeconds));
            return;
        }
        if (!$resp->successful()) {
            throw new \RuntimeException('GitHub fetch failed: ' . $resp->status());
        }
        $items = $resp->json() ?? [];
        if (empty($items)) {
            // Move to next repository
            $nextContext = $this->context;
            $nextContext['cursor']['repo_index'] = $repoIndex + 1;
            $nextContext['cursor']['page'] = 1;
            Bus::chain([
                new FetchIntegrationPage($this->integration, $nextContext),
            ])->onConnection('redis')->onQueue('migration')->dispatch();
            return;
        }

        // Next page for same repo
        $nextContext = $this->context;
        $nextContext['cursor']['page'] = $page + 1;

        Bus::chain([
            new ProcessIntegrationPage($this->integration, $items, $this->context),
            new FetchIntegrationPage($this->integration, $nextContext),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }
}



