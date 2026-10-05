<?php

namespace App\Services\Jev;

use InvalidArgumentException;

/**
 * One typed question for Jev: a Choice over declared options, a Noul (yes/no
 * probability) or a Score over ordered levels.
 */
final readonly class JevQuestion
{
    public const CHOICE = 'choice';

    public const NOUL = 'noul';

    public const SCORE = 'score';

    /**
     * @param  array<string, ?string>|list<string>|array{true?: string, false?: string}|null  $criteria
     */
    private function __construct(
        public string $type,
        public string $instructions,
        public ?array $criteria,
    ) {}

    /**
     * @param  array<string, ?string>  $options  Option name => description (null when the name says it all)
     */
    public static function choice(string $instructions, array $options): self
    {
        if (count($options) < 2 || count($options) > 255) {
            throw new InvalidArgumentException('A Jev choice needs between 2 and 255 options.');
        }

        return new self(self::CHOICE, $instructions, $options);
    }

    public static function noul(string $instructions, ?string $whenTrue = null, ?string $whenFalse = null): self
    {
        $criteria = array_filter(['true' => $whenTrue, 'false' => $whenFalse], fn (?string $value): bool => $value !== null);

        return new self(self::NOUL, $instructions, $criteria === [] ? null : $criteria);
    }

    /**
     * @param  list<string>  $levels  Concrete descriptions, lowest first
     */
    public static function score(string $instructions, array $levels): self
    {
        if (count($levels) < 2 || count($levels) > 10) {
            throw new InvalidArgumentException('A Jev score needs between 2 and 10 levels.');
        }

        return new self(self::SCORE, $instructions, array_values($levels));
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return match ($this->type) {
            self::CHOICE => array_map('strval', array_keys($this->criteria ?? [])),
            self::SCORE => array_map('strval', array_keys($this->criteria ?? [])),
            default => [],
        };
    }

    /**
     * @return array{type: string, instructions: string, criteria?: array<mixed>}
     */
    public function toPayload(): array
    {
        $payload = ['type' => $this->type, 'instructions' => $this->instructions];

        if ($this->criteria !== null) {
            $payload['criteria'] = $this->criteria;
        }

        return $payload;
    }
}
