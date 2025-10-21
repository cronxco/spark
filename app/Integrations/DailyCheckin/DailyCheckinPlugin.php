<?php

namespace App\Integrations\DailyCheckin;

use App\Integrations\Base\ManualPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use InvalidArgumentException;

class DailyCheckinPlugin extends ManualPlugin
{
    public static function getIdentifier(): string
    {
        return 'daily_checkin';
    }

    public static function getDisplayName(): string
    {
        return 'Daily Check-in';
    }

    public static function getDescription(): string
    {
        return 'Rate your physical and mental energy levels twice daily.';
    }

    public static function getConfigurationSchema(): array
    {
        return [];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'checkin' => [
                'label' => 'Daily Check-in',
                'schema' => [],
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'o-clipboard-document-check';
    }

    public static function getAccentColor(): string
    {
        return 'primary';
    }

    public static function getDomain(): string
    {
        return 'health';
    }

    public static function getActionTypes(): array
    {
        return [
            'had_morning_checkin' => [
                'icon' => 'o-sun',
                'display_name' => 'Morning Check-in',
                'description' => 'Morning energy levels recorded',
                'display_with_object' => false,
                'value_unit' => '/10',
                'value_formatter' => '{{ round($value) }}<span class="text-[0.875em]">/10</span>',
                'hidden' => false,
            ],
            'had_afternoon_checkin' => [
                'icon' => 'o-moon',
                'display_name' => 'Afternoon Check-in',
                'description' => 'Afternoon energy levels recorded',
                'display_with_object' => false,
                'value_unit' => '/10',
                'value_formatter' => '{{ round($value) }}<span class="text-[0.875em]">/10</span>',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'physical_energy' => [
                'icon' => 'o-bolt',
                'display_name' => 'Physical Energy',
                'description' => 'Physical energy rating (1-5)',
                'display_with_object' => false,
                'value_unit' => 'out of 5',
                'hidden' => false,
            ],
            'mental_energy' => [
                'icon' => 'o-light-bulb',
                'display_name' => 'Mental Energy',
                'description' => 'Mental energy rating (1-5)',
                'display_with_object' => false,
                'value_unit' => 'out of 5',
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'day' => [
                'icon' => 'o-calendar',
                'display_name' => 'Day',
                'description' => 'A calendar day',
                'hidden' => false,
            ],
            'user' => [
                'icon' => 'o-user',
                'display_name' => 'User',
                'description' => 'The user performing the check-in',
                'hidden' => false,
            ],
        ];
    }

    /**
     * Create or update a check-in event
     *
     * @param  Integration  $integration  The integration instance
     * @param  string  $period  Either 'morning' or 'afternoon'
     * @param  int  $physical  Physical energy rating (1-5)
     * @param  int  $mental  Mental energy rating (1-5)
     * @param  string  $date  Date in Y-m-d format
     * @return Event The created or updated event
     */
    public function createCheckinEvent(
        Integration $integration,
        string $period,
        int $physical,
        int $mental,
        string $date
    ): Event {
        // Validate period
        if (! in_array($period, ['morning', 'afternoon'])) {
            throw new InvalidArgumentException('Period must be either "morning" or "afternoon"');
        }

        // Validate ratings
        if ($physical < 1 || $physical > 5 || $mental < 1 || $mental > 5) {
            throw new InvalidArgumentException('Energy ratings must be between 1 and 5');
        }

        // Create the target "day" object once
        $dayObject = EventObject::firstOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $date,
            ],
            [
                'time' => $date . ' 00:00:00',
                'content' => null,
                'metadata' => [],
            ]
        );

        // Create or get user object as the actor
        $user = User::find($integration->user_id);
        $userObject = EventObject::firstOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'user',
                'type' => 'user',
                'title' => $user ? $user->name : 'User',
            ],
            [
                'time' => now(),
                'content' => null,
                'metadata' => [],
            ]
        );

        // Determine action and default time based on period
        $action = $period === 'morning' ? 'had_morning_checkin' : 'had_afternoon_checkin';
        $defaultTime = $period === 'morning' ? $date . ' 08:00:00' : $date . ' 17:00:00';

        // Calculate combined value (out of 10)
        $combinedValue = $physical + $mental;

        // Create or update the event
        $sourceId = 'daily_checkin_' . $period . '_' . $date;

        $event = Event::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => $sourceId,
            ],
            [
                'time' => now(),  // Use current time to record when they actually checked in
                'service' => 'daily_checkin',
                'domain' => self::getDomain(),
                'action' => $action,
                'value' => $combinedValue,
                'value_multiplier' => 1,
                'value_unit' => 'out of 10',
                'event_metadata' => [
                    'period' => $period,
                    'physical_energy' => $physical,
                    'mental_energy' => $mental,
                    'date' => $date,
                ],
                'target_id' => $dayObject->id,
                'actor_id' => $userObject->id,
            ]
        );

        // Create or update blocks for physical and mental energy
        $event->createBlock([
            'title' => 'Physical Energy',
            'block_type' => 'physical_energy',
            'value' => $physical,
            'value_multiplier' => 1,
            'value_unit' => 'out of 5',
            'metadata' => ['period' => $period],
            'time' => $event->time,
        ]);

        $event->createBlock([
            'title' => 'Mental Energy',
            'block_type' => 'mental_energy',
            'value' => $mental,
            'value_multiplier' => 1,
            'value_unit' => 'out of 5',
            'metadata' => ['period' => $period],
            'time' => $event->time,
        ]);

        return $event;
    }

    /**
     * Get check-in events for a specific date
     *
     * @param  int|string  $userId  The user ID
     * @param  string  $date  Date in Y-m-d format
     * @return array Array with 'morning' and 'afternoon' events (null if not found)
     */
    public function getCheckinsForDate(int|string $userId, string $date): array
    {
        $events = Event::whereHas('integration', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->where('service', 'daily_checkin')
            ->whereIn('action', ['had_morning_checkin', 'had_afternoon_checkin'])
            ->whereDate('time', $date)
            ->with('blocks')
            ->get();

        return [
            'morning' => $events->firstWhere('action', 'had_morning_checkin'),
            'afternoon' => $events->firstWhere('action', 'had_afternoon_checkin'),
        ];
    }
}
