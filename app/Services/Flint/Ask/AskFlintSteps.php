<?php

namespace App\Services\Flint\Ask;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * The steps Flint took to answer, in the reader's words.
 *
 * Each tool call becomes a step that says what Flint looked at ("Searched
 * events for “flight”"), what it covered ("Sun 22 to Sat 28 Nov"), what it
 * found ("1 match") and the records it found, so the app can show the work
 * while it happens and again when the conversation is reopened. A model
 * round that thought before acting adds a reasoning step with its summary.
 * Raw arguments and results never leave the server.
 */
class AskFlintSteps
{
    /** Records shown under one step; the count says how many there were. */
    public const MAX_RECORDS = 3;

    /** Keys a tool result lists records under, and the kind each holds. */
    private const RECORD_LISTS = [
        'events' => 'event',
        'objects' => 'object',
        'blocks' => 'block',
        'results' => null,
        'data' => null,
        'items' => null,
    ];

    /**
     * Steps from a stored assistant message's `steps` column.
     *
     * @param  array<int, array<string, mixed>>  $stored
     * @param  array<string, int>  $durations  milliseconds by tool call id
     * @return array<int, array<string, mixed>>
     */
    public static function fromStored(array $stored, array $durations = []): array
    {
        $steps = [];

        foreach ($stored as $index => $round) {
            if (! is_array($round)) {
                continue;
            }

            $reasoning = self::reasoningText($round['reasoning'] ?? null);
            if ($reasoning !== null) {
                $steps[] = self::reasoning("reasoning-{$index}", $reasoning);
            }

            foreach ((array) ($round['tool_calls'] ?? []) as $call) {
                if (! is_array($call) || ! is_string($call['name'] ?? null)) {
                    continue;
                }

                $id = (string) ($call['id'] ?? Str::uuid());
                $failed = (bool) ($call['failed'] ?? false) || (bool) ($call['denied'] ?? false);

                $steps[] = self::finished(
                    self::started($id, $call['name'], self::arguments($call['arguments'] ?? [])),
                    $call['result'] ?? null,
                    $durations[$id] ?? null,
                    $failed,
                );
            }
        }

        return $steps;
    }

    /**
     * A step as it starts: its label and what it covers.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function started(string $id, string $tool, array $arguments): array
    {
        [$label, $glyph] = self::label($tool, $arguments);

        return [
            'id' => $id,
            'type' => 'tool',
            'tool' => $tool,
            'glyph' => $glyph,
            'label' => $label,
            'detail' => self::scope($arguments),
            'status' => 'running',
            'result' => null,
            'records' => [],
            'duration_ms' => null,
        ];
    }

    /**
     * The same step once its result is in.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public static function finished(array $step, mixed $result, ?int $durationMs = null, bool $failed = false): array
    {
        $decoded = self::decode($result);
        $isError = $failed || self::isError($result, $decoded);
        [$count, $records] = $isError ? [null, []] : self::records($decoded, $step['tool'] ?? '');

        return [
            ...$step,
            'status' => $isError ? 'failed' : 'done',
            'result' => $isError ? 'Couldn’t read this' : self::resultLine($count, $decoded, (string) ($step['tool'] ?? '')),
            'records' => $records,
            'duration_ms' => $durationMs,
        ];
    }

    /** @return array<string, mixed> */
    public static function reasoning(string $id, string $text): array
    {
        return [
            'id' => $id,
            'type' => 'reasoning',
            'tool' => null,
            'glyph' => 'lightbulb',
            'label' => 'Thought it through',
            'detail' => $text,
            'status' => 'done',
            'result' => null,
            'records' => [],
            'duration_ms' => null,
        ];
    }

    /**
     * What a tool did, in the past tense, and the glyph that goes with it.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: string}
     */
    public static function label(string $tool, array $arguments = []): array
    {
        $query = self::quoted($arguments['query'] ?? $arguments['q'] ?? null);

        return match (true) {
            str_contains($tool, 'search-events') => [$query ? "Searched events for {$query}" : 'Searched events', 'magnifying-glass'],
            str_contains($tool, 'search-objects') => [$query ? "Searched people, places and things for {$query}" : 'Searched people, places and things', 'magnifying-glass'],
            str_contains($tool, 'search-blocks') => [$query ? "Searched details for {$query}" : 'Searched details', 'magnifying-glass'],
            str_contains($tool, 'events-by-filter') => [self::filterLabel($arguments), 'list'],
            str_contains($tool, 'day-summary') => ['Read the day', 'sun'],
            str_contains($tool, 'metric-trend') => ['Checked a trend', 'chart-line'],
            str_contains($tool, 'baselines') => ['Checked your usual ranges', 'chart-line'],
            str_contains($tool, 'check-ins') => ['Read your check-ins', 'face-smile'],
            str_contains($tool, 'money-plan') => ['Read the money plan', 'sterling-sign'],
            str_contains($tool, 'flint-notes') => ['Read Flint’s notes', 'note-sticky'],
            str_contains($tool, 'flint-digest') => ['Read the latest digest', 'newspaper'],
            str_contains($tool, 'integrations') => ['Listed your integrations', 'plug'],
            str_contains($tool, 'get-event') => ['Opened an event', 'calendar'],
            str_contains($tool, 'get-object') => ['Opened a record', 'cube'],
            str_contains($tool, 'get-block') => ['Opened a detail', 'cube'],
            $tool === 'tag-event' => ['Tagged an event', 'tag'],
            $tool === 'set-event-note' => ['Wrote a note on an event', 'note-sticky'],
            $tool === 'update-record' => ['Edited a record', 'pen'],
            $tool === 'link-records' => ['Linked two records', 'link'],
            $tool === 'delete-event' => ['Moved an event to the bin', 'trash'],
            $tool === 'log-event' => ['Logged something new', 'plus'],
            $tool === 'run-action' => ['Ran a command', 'bolt'],
            $tool === 'leave-note' => ['Kept a note for later', 'note-sticky'],
            default => [Str::of($tool)->replace(['-tool', '_tool'], '')->replace(['-', '_'], ' ')->ucfirst()->toString(), 'gear'],
        };
    }

    /**
     * What a call covered: its dates, metric or service, in a short line.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function scope(array $arguments): ?string
    {
        $from = self::day($arguments['from_date'] ?? $arguments['from'] ?? $arguments['start_date'] ?? null);
        $to = self::day($arguments['to_date'] ?? $arguments['to'] ?? $arguments['end_date'] ?? null);
        $date = self::day($arguments['date'] ?? null);

        $parts = array_values(array_filter([
            match (true) {
                $from !== null && $to !== null && $from !== $to => "{$from} to {$to}",
                $from !== null => "From {$from}",
                $to !== null => "Up to {$to}",
                $date !== null => $date,
                default => null,
            },
            self::words($arguments['metric'] ?? $arguments['metric_key'] ?? null),
            self::words($arguments['service'] ?? null),
            self::words($arguments['domain'] ?? null),
        ]));

        return $parts === [] ? null : Str::ucfirst(implode(', ', $parts));
    }

    /** @return array<string, mixed> */
    private static function arguments(mixed $arguments): array
    {
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true);
        }

        return is_array($arguments) ? $arguments : [];
    }

    /** @param array<string, mixed> $arguments */
    private static function filterLabel(array $arguments): string
    {
        $service = self::words($arguments['service'] ?? null);
        $action = self::words($arguments['action'] ?? null);

        return match (true) {
            $service === 'calendar' || str_contains((string) $action, 'calendar') => 'Listed your calendar',
            $action !== null => "Listed events: {$action}",
            $service !== null => "Listed {$service} events",
            default => 'Listed events',
        };
    }

    private static function reasoningText(mixed $reasoning): ?string
    {
        if (is_array($reasoning)) {
            $reasoning = implode("\n\n", array_filter(array_map(
                fn (mixed $part): ?string => is_string($part) ? $part : (is_array($part) ? ($part['text'] ?? null) : null),
                $reasoning,
            )));
        }

        $text = trim((string) $reasoning);

        return $text === '' ? null : Str::limit($text, 600);
    }

    private static function decode(mixed $result): mixed
    {
        if (! is_string($result)) {
            return $result;
        }

        $decoded = json_decode($result, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $result;
    }

    private static function isError(mixed $raw, mixed $decoded): bool
    {
        if (is_array($decoded)) {
            return isset($decoded['error']) && ! isset($decoded['events']) && ! isset($decoded['change']);
        }

        return is_string($raw) && preg_match('/^\s*(error|failed|authentication required)/i', $raw) === 1;
    }

    /**
     * How many records a result held, and the first few as references.
     *
     * @return array{0: int|null, 1: array<int, array{kind: string, id: string, title: string}>}
     */
    private static function records(mixed $decoded, string $tool): array
    {
        if (! is_array($decoded)) {
            return [null, []];
        }

        if (is_array($decoded['change'] ?? null)) {
            $change = $decoded['change'];

            return [null, array_filter([self::reference($change, (string) ($change['kind'] ?? 'event'))])];
        }

        foreach (self::RECORD_LISTS as $key => $kind) {
            if (! is_array($decoded[$key] ?? null) || ! array_is_list($decoded[$key])) {
                continue;
            }

            $kind ??= self::kindFor($tool);
            $list = $decoded[$key];
            $count = (int) ($decoded['meta']['total'] ?? $decoded['meta']['count'] ?? $decoded['total'] ?? count($list));

            $records = collect($list)
                ->map(fn (mixed $item): ?array => is_array($item) ? self::reference($item['event'] ?? $item['object'] ?? $item['block'] ?? $item, $kind) : null)
                ->filter()
                ->unique('id')
                ->take(self::MAX_RECORDS)
                ->values()
                ->all();

            return [$count, $records];
        }

        if (array_is_list($decoded)) {
            return [count($decoded), []];
        }

        if (isset($decoded['id']) && (str_contains($tool, 'get-event') || str_contains($tool, 'get-object') || str_contains($tool, 'get-block'))) {
            return [null, array_filter([self::reference($decoded, self::kindFor($tool))])];
        }

        return [null, []];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{kind: string, id: string, title: string}|null
     */
    private static function reference(array $item, string $kind): ?array
    {
        $id = $item['id'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        $title = $item['title']
            ?? (is_array($item['target'] ?? null) ? ($item['target']['title'] ?? null) : null)
            ?? $item['display_name']
            ?? $item['name']
            ?? null;

        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        return ['kind' => $kind, 'id' => strtolower($id), 'title' => Str::limit(trim($title), 60)];
    }

    private static function resultLine(?int $count, mixed $decoded, string $tool): ?string
    {
        if ($count !== null) {
            return match (true) {
                $count === 0 => 'Nothing found',
                str_contains($tool, 'search') => $count === 1 ? '1 match' : "{$count} matches",
                str_contains($tool, 'integrations') => $count === 1 ? '1 integration' : "{$count} integrations",
                default => $count === 1 ? '1 found' : "{$count} found",
            };
        }

        if (is_array($decoded) && is_string($decoded['change']['summary'] ?? null)) {
            return Str::limit($decoded['change']['summary'], 80);
        }

        return null;
    }

    private static function kindFor(string $tool): string
    {
        return match (true) {
            str_contains($tool, 'object') => 'object',
            str_contains($tool, 'block') => 'block',
            default => 'event',
        };
    }

    private static function quoted(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return '“' . Str::limit(trim($value), 40) . '”';
    }

    private static function words(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::of($value)->replace(['_', '-'], ' ')->lower()->toString();
    }

    /** "Sun 22 Nov" for a date the model passed, or null when it isn't one. */
    private static function day(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('D j M');
        } catch (Throwable) {
            return null;
        }
    }
}
