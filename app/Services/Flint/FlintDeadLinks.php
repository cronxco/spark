<?php

namespace App\Services\Flint;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;

/**
 * Removes Spark event links in Flint prose that lead nowhere.
 *
 * A model sometimes retypes an event id instead of copying it: in the week to
 * 5 November 2026 one briefing linked `/events/EM41PV` and another a UUID with
 * one group changed, so both links opened an empty page. A link whose target
 * is not a UUID, or is not one of the user's own events, is replaced with its
 * words, and the caller is told which ones so it can copy the right id.
 */
class FlintDeadLinks
{
    /** A markdown link to a Spark event, capturing the words and whatever follows `/events/`. */
    private const LINK_PATTERN = '~\[([^\]]+)\]\((?:https?://[^/\s)]+)?/events/([^)\s?#]+)[^)\s]*\)~';

    private const UUID_PATTERN = '~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i';

    /** @var array<string, bool> event id => resolves, for this instance's user */
    private array $resolved = [];

    public function __construct(private User $user) {}

    /**
     * The text with every dead event link replaced by its words, and the
     * targets that were removed.
     *
     * @return array{text: string|null, removed: array<int, string>}
     */
    public function strip(?string $markdown): array
    {
        if ($markdown === null || ! str_contains($markdown, '/events/')) {
            return ['text' => $markdown, 'removed' => []];
        }

        preg_match_all(self::LINK_PATTERN, $markdown, $matches);
        $this->resolve($matches[2]);

        $removed = [];
        $text = preg_replace_callback(self::LINK_PATTERN, function (array $match) use (&$removed): string {
            if ($this->resolved[strtolower($match[2])] ?? false) {
                return $match[0];
            }
            $removed[] = $match[2];

            return $match[1];
        }, $markdown);

        return ['text' => $text, 'removed' => array_values(array_unique($removed))];
    }

    /** @param array<int, string> $targets */
    private function resolve(array $targets): void
    {
        $unknown = collect($targets)
            ->map(fn (string $target): string => strtolower($target))
            ->reject(fn (string $target): bool => array_key_exists($target, $this->resolved))
            ->unique();

        $uuids = $unknown->filter(fn (string $target): bool => preg_match(self::UUID_PATTERN, $target) === 1);
        foreach ($unknown->diff($uuids) as $target) {
            $this->resolved[$target] = false;
        }
        if ($uuids->isEmpty()) {
            return;
        }

        $found = Event::query()
            ->whereIn('id', $uuids->values()->all())
            ->whereIn('integration_id', Integration::query()->where('user_id', $this->user->id)->select('id'))
            ->pluck('id')
            ->map(fn (mixed $id): string => strtolower((string) $id))
            ->all();

        foreach ($uuids as $target) {
            $this->resolved[$target] = in_array($target, $found, true);
        }
    }
}
