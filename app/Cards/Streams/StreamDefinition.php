<?php

namespace App\Cards\Streams;

class StreamDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $icon,
        public readonly string $color = 'primary',
        public readonly ?string $description = null,
    ) {}

    /**
     * Get all available streams.
     *
     * @return array<string, StreamDefinition>
     */
    public static function all(): array
    {
        return [
            'day' => new self(
                id: 'day',
                name: 'Day',
                icon: 'o-calendar',
                color: 'primary',
                description: 'Daily check-ins, stats, and reflections',
            ),
            'health' => new self(
                id: 'health',
                name: 'Health',
                icon: 'o-heart',
                color: 'error',
                description: 'Deep-dive into your health data',
            ),
        ];
    }

    /**
     * Get a stream definition by ID.
     */
    public static function find(string $id): ?self
    {
        return self::all()[$id] ?? null;
    }
}
