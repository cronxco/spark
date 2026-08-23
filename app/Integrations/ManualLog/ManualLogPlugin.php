<?php

namespace App\Integrations\ManualLog;

use App\Integrations\Base\ManualPlugin;
use App\Jobs\OAuth\ManualLog\BoardGameGeekEnrichmentPull;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Catch-all for logging activities that have no automatic source - a glass
 * of wine, a trip to the cinema, a board game night. One shared Integration
 * instance per user (like DailyCheckinPlugin), with a small registry of
 * loggable activity types. Adding a new one is just adding an entry to
 * getActionTypes()/getObjectTypes()/ACTIVITY_TARGETS - no schema change.
 */
class ManualLogPlugin extends ManualPlugin
{
    /**
     * Per-action target object shape and event domain. Keys must match
     * getActionTypes(). Not part of the IntegrationPlugin contract - this is
     * this plugin's own internal registry for createManualEvent().
     */
    private const ACTIVITY_TARGETS = [
        'drank_wine' => ['concept' => 'wine', 'type' => 'wine', 'domain' => 'health'],
        'watched_at_cinema' => ['concept' => 'media', 'type' => 'cinema_visit', 'domain' => 'media'],
        'played_board_game' => ['concept' => 'game', 'type' => 'board_game', 'domain' => 'media'],
    ];

    public static function getIdentifier(): string
    {
        return 'manual_log';
    }

    public static function getDisplayName(): string
    {
        return 'Manual Log';
    }

    public static function getDescription(): string
    {
        return 'Log activities with no automatic source - wine, cinema trips, board games, and more.';
    }

    public static function getConfigurationSchema(): array
    {
        return [];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'log' => [
                'label' => 'Manual Log',
                'schema' => [],
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.circle-plus';
    }

    public static function getAccentColor(): string
    {
        return 'secondary';
    }

    public static function getDomain(): string
    {
        return 'media';
    }

    public static function getActionTypes(): array
    {
        return [
            'drank_wine' => [
                'icon' => 'fas.wine-glass',
                'display_name' => 'Drank Wine',
                'description' => 'A glass or bottle of wine',
                'display_with_object' => true,
                'value_unit' => '/5',
                'hidden' => false,
            ],
            'watched_at_cinema' => [
                'icon' => 'fas.film',
                'display_name' => 'Watched at the Cinema',
                'description' => 'A trip to the cinema',
                'display_with_object' => true,
                'value_unit' => '/5',
                'hidden' => false,
            ],
            'played_board_game' => [
                'icon' => 'fas.dice',
                'display_name' => 'Played a Board Game',
                'description' => 'A board game session',
                'display_with_object' => true,
                'value_unit' => '/5',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'board_game_details' => [
                'icon' => 'fas.dice',
                'display_name' => 'Board Game Details',
                'description' => 'Player count, playing time, and rating from BoardGameGeek',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'manual_log_user' => [
                'icon' => 'fas.user',
                'display_name' => 'User',
                'description' => 'The user logging the activity',
                'hidden' => false,
            ],
            'wine' => [
                'icon' => 'fas.wine-glass',
                'display_name' => 'Wine',
                'description' => 'A wine that was drunk',
                'hidden' => false,
            ],
            'cinema_visit' => [
                'icon' => 'fas.film',
                'display_name' => 'Cinema Visit',
                'description' => 'A film watched at the cinema',
                'hidden' => false,
            ],
            'board_game' => [
                'icon' => 'fas.dice',
                'display_name' => 'Board Game',
                'description' => 'A board game played',
                'hidden' => false,
            ],
        ];
    }

    /**
     * Log one manual activity.
     *
     * @param  string  $actionType  One of the keys in ACTIVITY_TARGETS/getActionTypes()
     * @param  string  $title  The thing being logged (wine name, film title, game name)
     * @param  float|null  $rating  Optional 1-5 rating
     * @param  string|null  $notes  Optional free-text notes
     */
    public function createManualEvent(
        Integration $integration,
        string $actionType,
        string $title,
        ?float $rating = null,
        ?string $notes = null,
    ): Event {
        if (! isset(self::ACTIVITY_TARGETS[$actionType])) {
            throw new InvalidArgumentException("Unknown manual log activity type: {$actionType}");
        }

        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            throw new InvalidArgumentException('Rating must be between 1 and 5');
        }

        $target = self::ACTIVITY_TARGETS[$actionType];

        $user = User::find($integration->user_id);
        $userObject = EventObject::firstOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'user',
                'type' => 'manual_log_user',
                'title' => $user?->name ?? 'User',
            ],
            ['time' => now()]
        );

        $targetObject = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => $target['concept'],
            'type' => $target['type'],
            'title' => $title,
            'time' => now(),
            'metadata' => array_filter([
                'rating' => $rating,
                'notes' => $notes,
            ], fn ($value) => $value !== null),
        ]);

        // events.value is a bigint - a fractional rating (e.g. 4.5) has to be
        // stored as an integer numerator against a multiplier, the same way
        // the rest of the codebase represents non-integer values (see
        // Event::getFormattedValueAttribute(): value / value_multiplier).
        $valueMultiplier = 10;

        $event = Event::create([
            'source_id' => 'manual_log_' . $actionType . '_' . Str::uuid(),
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'service' => self::getIdentifier(),
            'domain' => $target['domain'],
            'action' => $actionType,
            'value' => $rating !== null ? (int) round($rating * $valueMultiplier) : null,
            'value_multiplier' => $rating !== null ? $valueMultiplier : 1,
            'value_unit' => $rating !== null ? '/5' : null,
            'target_id' => $targetObject->id,
            'event_metadata' => array_filter([
                'notes' => $notes,
            ], fn ($value) => $value !== null),
        ]);

        if ($actionType === 'played_board_game') {
            BoardGameGeekEnrichmentPull::dispatch($integration, $event->id, $title);
        }

        return $event;
    }
}
