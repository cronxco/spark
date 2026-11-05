<?php

namespace App\Cards;

use App\Cards\Contracts\StreamCard;
use App\Cards\Streams\StreamDefinition;
use App\Models\User;
use Illuminate\Support\Collection;

class CardRegistry
{
    /**
     * @var array<string, array<class-string<StreamCard>>>
     */
    protected static array $cards = [];

    /**
     * Register a card for a specific stream.
     *
     * @param  class-string<StreamCard>  $cardClass
     */
    public static function register(string $streamId, string $cardClass): void
    {
        if (! isset(static::$cards[$streamId])) {
            static::$cards[$streamId] = [];
        }

        static::$cards[$streamId][] = $cardClass;
    }

    /**
     * Get all registered card classes for a stream.
     *
     * @return array<class-string<StreamCard>>
     */
    public static function getCards(string $streamId): array
    {
        return static::$cards[$streamId] ?? [];
    }

    /**
     * Get eligible card instances for a user, stream, and date.
     *
     * @return Collection<int, StreamCard>
     */
    public static function getEligibleCards(string $streamId, User $user, string $date): Collection
    {
        $now = user_now($user);
        $cardClasses = static::getCards($streamId);

        $cards = collect($cardClasses)
            ->map(fn (string $class) => new $class)
            ->filter(fn (StreamCard $card) => $card->isEligible($now, $user, $date))
            ->sortByDesc(fn (StreamCard $card) => $card->getPriority())
            ->values();

        return $cards;
    }

    /**
     * Check if a stream has any eligible cards.
     */
    public static function hasEligibleCards(string $streamId, User $user, string $date): bool
    {
        return static::getEligibleCards($streamId, $user, $date)->isNotEmpty();
    }

    /**
     * Get all streams that have eligible cards for a user and date.
     *
     * @return Collection<string, StreamDefinition>
     */
    public static function getStreamsWithCards(User $user, string $date): Collection
    {
        $allStreams = StreamDefinition::all();

        return collect($allStreams)
            ->filter(fn ($stream) => static::hasEligibleCards($stream->id, $user, $date));
    }
}
