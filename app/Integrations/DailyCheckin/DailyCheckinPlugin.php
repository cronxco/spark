<?php

namespace App\Integrations\DailyCheckin;

use App\Integrations\Base\ManualPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\Media\MediaDownloadHelper;
use App\Services\PlaceDetectionService;
use Illuminate\Support\Str;
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
        return 'fas.clipboard-check';
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
                'icon' => 'fas.sun',
                'display_name' => 'Morning Check-in',
                'description' => 'Morning energy levels recorded',
                'display_with_object' => false,
                'value_unit' => '/10',
                'value_formatter' => '{{ round($value) }}<span class="text-[0.875em]">/10</span>',
                'hidden' => false,
            ],
            'had_afternoon_checkin' => [
                'icon' => 'fas.moon',
                'display_name' => 'Afternoon Check-in',
                'description' => 'Afternoon energy levels recorded',
                'display_with_object' => false,
                'value_unit' => '/10',
                'value_formatter' => '{{ round($value) }}<span class="text-[0.875em]">/10</span>',
                'hidden' => false,
            ],
            'shared_a_photo' => [
                'icon' => 'fas.camera',
                'display_name' => 'Shared a Photo',
                'description' => 'A photo shared to Spark for the day',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'physical_energy' => [
                'icon' => 'fas.bolt',
                'display_name' => 'Physical Energy',
                'description' => 'Physical energy rating (1-5)',
                'display_with_object' => false,
                'value_unit' => 'out of 5',
                'hidden' => false,
            ],
            'mental_energy' => [
                'icon' => 'fas.lightbulb',
                'display_name' => 'Mental Energy',
                'description' => 'Mental energy rating (1-5)',
                'display_with_object' => false,
                'value_unit' => 'out of 5',
                'hidden' => false,
            ],
            'photo' => [
                'icon' => 'fas.camera',
                'display_name' => 'Photo',
                'description' => 'A photo shared to Spark',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'day' => [
                'icon' => 'fas.calendar-day',
                'display_name' => 'Day',
                'description' => 'A calendar day',
                'hidden' => false,
            ],
            'user' => [
                'icon' => 'fas.user',
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
     * @param  float|null  $latitude  Optional latitude coordinate
     * @param  float|null  $longitude  Optional longitude coordinate
     * @param  string|null  $address  Optional address string
     * @param  string|null  $notes  Optional free-text notes
     * @return Event The created or updated event
     */
    public function createCheckinEvent(
        Integration $integration,
        string $period,
        int $physical,
        int $mental,
        string $date,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $address = null,
        ?string $notes = null
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
                    'has_location' => $latitude !== null && $longitude !== null,
                    'notes' => $notes,
                ],
                'target_id' => $dayObject->id,
                'actor_id' => $userObject->id,
            ]
        );

        // Set location if coordinates provided
        if ($latitude !== null && $longitude !== null) {
            $event->setLocation($latitude, $longitude, $address, 'daily_checkin');

            // Link to place
            $placeService = app(PlaceDetectionService::class);
            $placeService->detectAndLinkPlaceForEvent($event);
        }

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
     * Attach a shared photo to the given day.
     *
     * Creates an event (action `shared_a_photo`) targeting the day object and
     * a `photo` block, then attaches the supplied image file to the block's
     * media library collection. Mirrors createCheckinEvent's day/user wiring.
     *
     * @param  Integration  $integration  The daily check-in integration
     * @param  string  $date  Date in Y-m-d format the photo belongs to
     * @param  string  $imagePath  Absolute path to the image file on disk
     * @param  string  $fileName  Original/desired file name (with extension)
     */
    public function createPhotoEvent(
        Integration $integration,
        string $date,
        string $imagePath,
        string $fileName,
    ): Event {
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

        $event = Event::create([
            'integration_id' => $integration->id,
            'source_id' => 'daily_checkin_photo_' . $date . '_' . Str::uuid(),
            'time' => now(),
            'service' => 'daily_checkin',
            'domain' => self::getDomain(),
            'action' => 'shared_a_photo',
            'event_metadata' => [
                'date' => $date,
                'source' => 'ios_share_extension',
            ],
            'target_id' => $dayObject->id,
            'actor_id' => $userObject->id,
        ]);

        $block = $event->createBlock([
            'title' => 'Photo',
            'block_type' => 'photo',
            'time' => $event->time,
            'metadata' => ['date' => $date],
        ]);

        app(MediaDownloadHelper::class)->attachMediaFromBase64(
            base64_encode((string) file_get_contents($imagePath)),
            $block,
            $fileName,
            'downloaded_images',
            ['source' => 'ios_share_extension'],
        );

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
            ->whereIn('source_id', [
                'daily_checkin_morning_' . $date,
                'daily_checkin_afternoon_' . $date,
            ])
            ->with('blocks')
            ->get();

        return [
            'morning' => $events->firstWhere('action', 'had_morning_checkin'),
            'afternoon' => $events->firstWhere('action', 'had_afternoon_checkin'),
        ];
    }
}
