<?php

namespace App\Services\Actions;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Support\SparkAbility;

/**
 * Turns a typed line such as "sync monzo" or "delete coffee at pret" into an
 * action plus the thing it acts on, so the CMD tab can run targeted actions
 * without the caller knowing any ids. The first word picks the action through
 * its declared verbs; the rest names the integration or event. Only a single
 * match is returned as `matched`, so a vague line never acts on the wrong item.
 */
final class ActionResolver
{
    public const MAX_CANDIDATES = 5;

    /** Events older than this are not offered as a target by name. */
    private const EVENT_WINDOW_DAYS = 90;

    public function __construct(private ActionRegistry $registry) {}

    /**
     * @return array{status: string, message?: string, action?: ActionDefinition, target?: array<string, mixed>, label?: string, candidates?: array<int, array{label: string, target: array<string, mixed>}>}
     */
    public function resolve(User $user, string $text): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $verb = strtolower((string) array_shift($words));

        $action = $this->registry->forSurface('native')
            ->first(fn (ActionDefinition $action): bool => ! $action->isNavigation()
                && in_array($verb, $action->verbs, true)
                && ($action->capability === null || SparkAbility::allows($user, $action->capability))
                && $this->entityField($action) !== null);

        if ($action === null) {
            return ['status' => 'none'];
        }

        $extra = [];
        if (isset($action->target['name'])) {
            $tag = $this->takeTag($words);
            if ($tag === null) {
                return ['status' => 'incomplete', 'message' => 'Add a #tag', 'action' => $action];
            }
            $extra['name'] = $tag;
        }

        $subject = trim(implode(' ', $words));
        if ($subject === '') {
            return ['status' => 'incomplete', 'message' => 'Name what to ' . $verb, 'action' => $action];
        }

        $field = $this->entityField($action);
        $candidates = $field === 'integration_id' ? $this->integrations($user, $subject) : $this->events($user, $subject);

        if ($candidates === []) {
            return ['status' => 'none', 'message' => "Nothing matches {$subject}"];
        }

        $candidates = array_map(fn (array $c): array => [
            'label' => $c['label'],
            'target' => [$field => $c['id']] + $extra,
            'exact' => $c['exact'],
        ], $candidates);

        $exact = array_values(array_filter($candidates, fn (array $c): bool => $c['exact']));
        $pick = count($candidates) === 1 ? $candidates[0] : (count($exact) === 1 ? $exact[0] : null);
        $strip = fn (array $c): array => ['label' => $c['label'], 'target' => $c['target']];

        if ($pick !== null) {
            return ['status' => 'matched', 'action' => $action, 'target' => $pick['target'], 'label' => $pick['label']];
        }

        return ['status' => 'ambiguous', 'action' => $action, 'candidates' => array_map($strip, $candidates)];
    }

    /** The one field that names a thing in the user's data. */
    private function entityField(ActionDefinition $action): ?string
    {
        foreach (['integration_id', 'event_id'] as $field) {
            if (isset($action->target[$field])) {
                return $field;
            }
        }

        return null;
    }

    /** Takes a trailing `#tag` off the words and returns it. */
    private function takeTag(array &$words): ?string
    {
        foreach ($words as $i => $word) {
            if (str_starts_with($word, '#') && strlen($word) > 1) {
                unset($words[$i]);
                $words = array_values($words);

                return substr($word, 1);
            }
        }

        return null;
    }

    /** @return array<int, array{id: string, label: string, exact: bool}> */
    private function integrations(User $user, string $subject): array
    {
        $like = $this->like($subject);

        return $user->integrations()
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(service) LIKE ?', [$like]))
            ->orderBy('name')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->map(function (Integration $integration) use ($subject): array {
                $label = $integration->name ?: ucfirst((string) $integration->service);

                return [
                    'id' => $integration->id,
                    'label' => $label,
                    'exact' => strcasecmp((string) $integration->name, $subject) === 0,
                ];
            })
            ->all();
    }

    /** @return array<int, array{id: string, label: string, exact: bool}> */
    private function events(User $user, string $subject): array
    {
        $integrationIds = $user->integrations()->pluck('id')->all();
        if ($integrationIds === []) {
            return [];
        }

        $like = $this->like($subject);

        return Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->where('time', '>=', now()->subDays(self::EVENT_WINDOW_DAYS))
            ->whereHas('target', fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [$like]))
            ->with('target')
            ->orderByDesc('time')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'label' => (string) $event->target?->title,
                'exact' => strcasecmp((string) $event->target?->title, $subject) === 0,
            ])
            ->all();
    }

    private function like(string $subject): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($subject)) . '%';
    }
}
