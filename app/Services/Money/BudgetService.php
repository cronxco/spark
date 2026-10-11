<?php

namespace App\Services\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Spending budgets: EventObject concept=budget, type=spending_budget. Spend is computed at
 * query time over SpendingScope for a calendar month; nothing about progress is stored.
 *
 * metadata.budget: amount (pence), period (month), categories, merchants_include,
 * merchants_exclude, accounts (object ids), people. A `part_of` relationship from an event to a
 * budget overrides category matching for that transaction.
 */
class BudgetService
{
    public const CONCEPT = 'budget';

    public const TYPE = 'spending_budget';

    /** Share of the limit at which a budget is flagged as at risk. */
    public const WARN_AT = 0.8;

    public function __construct(private SpendingScope $scope) {}

    /**
     * @return Collection<int, EventObject>
     */
    public function all(string $userId, bool $withArchived = false): Collection
    {
        return EventObject::where('user_id', $userId)
            ->where('concept', self::CONCEPT)
            ->where('type', self::TYPE)
            ->orderBy('title')
            ->get()
            ->reject(fn (EventObject $budget): bool => ! $withArchived && $this->archived($budget))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(string $userId, string $title, array $input): EventObject
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('A budget needs a name.');
        }

        $duplicate = EventObject::where('user_id', $userId)->where('concept', self::CONCEPT)
            ->where('type', self::TYPE)->where('title', $title)->exists();
        if ($duplicate) {
            throw new InvalidArgumentException("There is already a budget called {$title}.");
        }

        return EventObject::create([
            'user_id' => $userId,
            'concept' => self::CONCEPT,
            'type' => self::TYPE,
            'title' => $title,
            'time' => now(),
            'metadata' => ['budget' => $this->normalise($input)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $userId, string $budgetId, array $input, ?string $title = null): EventObject
    {
        $budget = $this->find($userId, $budgetId);
        $metadata = $budget->metadata ?? [];
        $metadata['budget'] = $this->normalise($input + ($metadata['budget'] ?? []));
        $attributes = ['metadata' => $metadata];
        if ($title !== null && trim($title) !== '') {
            $attributes['title'] = trim($title);
        }
        $budget->update($attributes);

        return $budget->refresh();
    }

    public function archive(string $userId, string $budgetId, bool $archived = true): EventObject
    {
        $budget = $this->find($userId, $budgetId);
        $metadata = $budget->metadata ?? [];
        $metadata['archived'] = $archived;
        $budget->update(['metadata' => $metadata]);

        return $budget->refresh();
    }

    /**
     * Put one transaction in a specific budget, or back to automatic matching with null.
     */
    public function assign(string $userId, string $eventId, ?string $budgetId): void
    {
        $event = Event::forUser($userId)->findOrFail($eventId);

        Relationship::where('user_id', $userId)->where('type', 'part_of')
            ->where('from_type', Event::class)->where('from_id', $event->id)
            ->where('to_type', EventObject::class)
            ->whereIn('to_id', EventObject::where('user_id', $userId)->where('concept', self::CONCEPT)->select('id'))
            ->delete();

        if ($budgetId !== null) {
            Relationship::createRelationship([
                'user_id' => $userId,
                'from_type' => Event::class,
                'from_id' => $event->id,
                'to_type' => EventObject::class,
                'to_id' => $this->find($userId, $budgetId)->id,
                'type' => 'part_of',
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function progress(string $userId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $start = $now->startOfMonth();
        $budgets = $this->all($userId);
        $entries = $this->scope->entries($userId, $start, $now->endOfMonth());
        $spent = $this->allocate($budgets, $entries);
        $elapsed = $now->day / $now->daysInMonth;

        return $budgets->map(function (EventObject $budget) use ($spent, $elapsed): array {
            $config = $budget->metadata['budget'] ?? [];
            $limit = (int) ($config['amount'] ?? 0);
            $used = (int) ($spent[(string) $budget->id] ?? 0);
            $ratio = $limit > 0 ? $used / $limit : 0.0;

            return [
                'id' => (string) $budget->id,
                'name' => $budget->title,
                'limit' => $limit,
                'spent' => $used,
                'remaining' => $limit - $used,
                'ratio' => $ratio,
                'expected_ratio' => $elapsed,
                'status' => match (true) {
                    $ratio > 1 => 'over',
                    $ratio >= self::WARN_AT || $ratio > $elapsed + 0.15 => 'at_risk',
                    default => 'ok',
                },
                'categories' => $config['categories'] ?? [],
                'people' => $config['people'] ?? [],
            ];
        })->sortByDesc('ratio')->values()->all();
    }

    /**
     * Each transaction counts toward exactly one budget: its explicit assignment, otherwise the
     * first budget (by name) whose rules match.
     *
     * @param  Collection<int, EventObject>  $budgets
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, int> Budget id => pence
     */
    public function allocate(Collection $budgets, Collection $entries): array
    {
        $known = $budgets->keyBy(fn (EventObject $budget): string => (string) $budget->id);
        $totals = [];

        foreach ($entries as $entry) {
            $target = $entry['budget_id'] !== null && $known->has($entry['budget_id'])
                ? $entry['budget_id']
                : $budgets->first(fn (EventObject $budget): bool => $this->matches($budget->metadata['budget'] ?? [], $entry))?->id;

            if ($target !== null) {
                $totals[(string) $target] = ($totals[(string) $target] ?? 0) + $entry['amount'];
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $entry
     */
    private function matches(array $config, array $entry): bool
    {
        $merchant = mb_strtolower($entry['merchant']);
        $lower = fn (array $values): array => array_map(fn ($value): string => mb_strtolower((string) $value), $values);

        if (in_array($merchant, $lower($config['merchants_exclude'] ?? []), true)) {
            return false;
        }
        if (($config['accounts'] ?? []) !== [] && ! in_array($entry['account_id'], array_map('strval', $config['accounts']), true)) {
            return false;
        }
        if (($config['people'] ?? []) !== [] && ! in_array(mb_strtolower((string) $entry['person']), $lower($config['people']), true)) {
            return false;
        }

        return in_array($entry['category'], $lower($config['categories'] ?? []), true)
            || in_array($merchant, $lower($config['merchants_include'] ?? []), true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalise(array $input): array
    {
        $amount = (int) ($input['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('A budget needs an amount above zero.');
        }

        $list = fn (string $key): array => array_values(array_unique(array_filter(
            array_map(fn ($value): string => trim((string) $value), (array) ($input[$key] ?? [])),
            fn (string $value): bool => $value !== '',
        )));

        $categories = array_map(fn (string $c): string => str_replace([' ', '-'], '_', mb_strtolower($c)), $list('categories'));

        return [
            'amount' => $amount,
            'period' => 'month',
            'categories' => array_values(array_unique($categories)),
            'merchants_include' => $list('merchants_include'),
            'merchants_exclude' => $list('merchants_exclude'),
            'accounts' => $list('accounts'),
            'people' => $list('people'),
        ];
    }

    private function find(string $userId, string $budgetId): EventObject
    {
        return EventObject::where('user_id', $userId)->where('concept', self::CONCEPT)
            ->where('type', self::TYPE)->findOrFail($budgetId);
    }

    private function archived(EventObject $budget): bool
    {
        return (bool) ($budget->metadata['archived'] ?? false);
    }
}
