<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Ai\EmbeddingClient;
use App\Services\Mobile\SearchDispatcher;
use App\Services\Search\RecencyRanking;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Runs a list of searches for one user with the recency signal off and on and
 * prints how the top results move, so the weight and half-life in
 * `spark.search.recency` can be checked against real searches before they
 * are changed.
 */
class CheckSearchRecency extends Command
{
    protected $signature = 'search:recency-check
                            {user : User id or email to search as}
                            {queries?* : Queries to run}
                            {--file= : A file with one query per line (blank lines and lines starting with # are skipped)}
                            {--mode=default : Search mode: default or semantic}
                            {--weight= : Recency weight to compare against no recency (default: the configured weight)}
                            {--half-life= : Recency half-life in days (default: the configured half-life)}
                            {--limit=10 : Results per kind to compare}';

    protected $description = 'Compare search results with the recency ranking signal off and on';

    public function handle(): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if (! $user) {
            $this->error('No user found for ' . $this->argument('user') . '.');

            return Command::FAILURE;
        }

        $queries = $this->queries();

        if ($queries === null) {
            return Command::FAILURE;
        }

        if ($queries === []) {
            $this->error('No queries given. Pass them as arguments or with --file.');

            return Command::FAILURE;
        }

        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['default', 'semantic'], true)) {
            $this->error('Mode must be default or semantic.');

            return Command::FAILURE;
        }

        $configured = new RecencyRanking;
        $halfLife = $this->option('half-life') !== null ? (float) $this->option('half-life') : $configured->halfLifeDays();
        $on = $configured->withWeight(
            $this->option('weight') !== null ? (float) $this->option('weight') : $configured->weight(),
            $halfLife,
        );
        $off = $on->withWeight(0);
        $limit = max(1, (int) $this->option('limit'));

        if (! $on->enabled()) {
            $this->warn('The recency weight is 0, so both runs are identical. Pass --weight to try a value.');
        }

        $this->info(sprintf(
            'Comparing %d %s for %s: recency off vs weight %s, half-life %s days (%s mode).',
            count($queries),
            Str::plural('query', count($queries)),
            $user->email,
            $on->weight(),
            $on->halfLifeDays(),
            $mode,
        ));

        $embeddings = $mode === 'semantic' ? app(EmbeddingClient::class) : null;
        $offSearch = new SearchDispatcher($embeddings, $off);
        $onSearch = new SearchDispatcher($embeddings, $on);

        $changedTop = 0;
        $moved = 0;

        foreach ($queries as $query) {
            $before = $offSearch->search($user, $mode, $query, $limit);
            $after = $onSearch->search($user, $mode, $query, $limit);

            $this->newLine();
            $this->line("<options=bold>“{$query}”</>");

            $rows = [];
            $topChanged = false;

            foreach (['events' => 'event', 'objects' => 'object'] as $key => $kind) {
                $beforeIds = $before[$key]->pluck('id')->values()->all();
                $afterIds = $after[$key]->pluck('id')->values()->all();

                if (($beforeIds[0] ?? null) !== ($afterIds[0] ?? null)) {
                    $topChanged = true;
                }

                foreach ($after[$key]->values() as $index => $model) {
                    $previous = array_search($model->id, $beforeIds, true);
                    $movement = $this->movement($previous === false ? null : $previous, $index);

                    if ($movement !== '=') {
                        $moved++;
                    }

                    $rows[] = [$kind, $index + 1, $previous === false ? '-' : $previous + 1, $movement, $this->title($model), $model->time?->toDateString() ?? '-'];
                }

                foreach ($before[$key]->values() as $index => $model) {
                    if (! in_array($model->id, $afterIds, true)) {
                        $rows[] = [$kind, '-', $index + 1, 'dropped', $this->title($model), $model->time?->toDateString() ?? '-'];
                    }
                }
            }

            if ($rows === []) {
                $this->line('  No results.');

                continue;
            }

            if ($topChanged) {
                $changedTop++;
            }

            $this->table(['Kind', 'On', 'Off', 'Move', 'Title', 'Date'], $rows);
        }

        $this->newLine();
        $this->info(sprintf(
            'Top result changed in %d of %d %s; %d %s moved.',
            $changedTop,
            count($queries),
            Str::plural('query', count($queries)),
            $moved,
            Str::plural('result', $moved),
        ));

        return Command::SUCCESS;
    }

    protected function resolveUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::query()->where('email', $identifier)->first();
        }

        return User::query()->find($identifier);
    }

    /**
     * The queries from the arguments and --file, trimmed, de-duplicated, in order.
     *
     * @return array<int, string>|null Null when the file cannot be read.
     */
    protected function queries(): ?array
    {
        $queries = collect((array) $this->argument('queries'));
        $file = $this->option('file');

        if ($file) {
            if (! File::isReadable($file)) {
                $this->error("Cannot read query file {$file}.");

                return null;
            }

            $queries = $queries->merge(
                collect(preg_split('/\R/', File::get($file)))
                    ->reject(fn (string $line): bool => str_starts_with(ltrim($line), '#'))
            );
        }

        return $queries
            ->map(fn ($query): string => trim((string) $query))
            ->filter(fn (string $query): bool => $query !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function movement(?int $before, int $after): string
    {
        return match (true) {
            $before === null => 'new',
            $before === $after => '=',
            $before > $after => '▲' . ($before - $after),
            default => '▼' . ($after - $before),
        };
    }

    protected function title(Model $model): string
    {
        $title = match (true) {
            $model instanceof Event => format_action_title($model->action) . ($model->target ? ' · ' . $model->target->title : ''),
            $model instanceof EventObject => $model->title ?? 'Untitled',
            default => (string) $model->getKey(),
        };

        return Str::limit($title, 60);
    }
}
