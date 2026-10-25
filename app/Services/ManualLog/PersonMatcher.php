<?php

namespace App\Services\ManualLog;

use App\Models\Person;
use Illuminate\Support\Str;

/**
 * Finds the people a typed name could mean, so a preview can say "Dan is Daniel" or ask which one.
 *
 * A name matches exactly when the titles are equal ignoring case and accents. Otherwise every typed word has to
 * start a word of the person's name, or the other way round for short names, so Dan reaches Daniel and Daniel reaches Dan.
 */
class PersonMatcher
{
    private const MIN_PARTIAL_LENGTH = 3;

    private const MAX_CANDIDATES = 5;

    /** Handles that address the app rather than a person, so `@flint` is always a Note to Flint. */
    private const RESERVED_HANDLES = ['flint'];

    /**
     * Whether a typed name is a reserved handle such as `@flint`, which never matches or creates a person.
     */
    public static function isReserved(string $name): bool
    {
        return in_array(Str::lower(ltrim(trim($name), '@')), self::RESERVED_HANDLES, true);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, array{name: string, state: 'existing'|'likely'|'ambiguous'|'new'|'reserved', matches: array<int, array{id: string, name: string}>}>
     */
    public function check(string $userId, array $names): array
    {
        $people = Person::query()->where('user_id', $userId)->get(['id', 'title']);

        return collect($names)
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => Str::lower($name))
            ->map(fn (string $name) => $this->match($name, $people->all()))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, Person>  $people
     * @return array{name: string, state: string, matches: array<int, array{id: string, name: string}>}
     */
    private function match(string $name, array $people): array
    {
        if (self::isReserved($name)) {
            return ['name' => $name, 'state' => 'reserved', 'matches' => []];
        }

        $key = $this->fold($name);
        $exact = array_values(array_filter($people, fn (Person $person) => $this->fold($person->title) === $key));

        if ($exact !== []) {
            return ['name' => $name, 'state' => 'existing', 'matches' => [$this->describe($exact[0])]];
        }

        $partial = collect($people)
            ->filter(fn (Person $person) => $this->isPartialMatch($key, $this->fold($person->title)))
            ->sortBy(fn (Person $person) => mb_strlen($person->title))
            ->take(self::MAX_CANDIDATES)
            ->map(fn (Person $person) => $this->describe($person))
            ->values()
            ->all();

        return [
            'name' => $name,
            'state' => match (count($partial)) {
                0 => 'new',
                1 => 'likely',
                default => 'ambiguous',
            },
            'matches' => $partial,
        ];
    }

    private function isPartialMatch(string $typed, string $title): bool
    {
        $typedWords = explode(' ', $typed);
        $titleWords = explode(' ', $title);

        $typedStartsTitle = collect($typedWords)->every(
            fn (string $word) => mb_strlen($word) >= self::MIN_PARTIAL_LENGTH
                && collect($titleWords)->contains(fn (string $other) => str_starts_with($other, $word))
        );

        $titleStartsTyped = collect($titleWords)->every(
            fn (string $word) => mb_strlen($word) >= self::MIN_PARTIAL_LENGTH
                && collect($typedWords)->contains(fn (string $other) => str_starts_with($other, $word))
        );

        return $typedStartsTitle || $titleStartsTyped;
    }

    private function fold(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($name))));
    }

    /**
     * @return array{id: string, name: string}
     */
    private function describe(Person $person): array
    {
        return ['id' => (string) $person->id, 'name' => $person->title];
    }
}
