<?php

namespace App\Jobs\Data\Oyster;

use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\Integration;
use App\Models\Relationship;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LinkOysterJourneyEventsJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public function __construct(
        public Integration $integration,
        public ?array $statementPeriod = null
    ) {}

    public function handle(): void
    {
        Log::info('Oyster: Linking journey events', [
            'integration_id' => $this->integration->id,
            'statement_period' => $this->statementPeriod,
        ]);

        // Get all touched_in events for this integration
        $query = Event::where('integration_id', $this->integration->id)
            ->where('service', 'oyster')
            ->where('action', 'touched_in_at')
            ->orderBy('time', 'asc');

        // Filter by statement period if provided
        if ($this->statementPeriod) {
            if (isset($this->statementPeriod['start'])) {
                $start = $this->statementPeriod['start'] instanceof Carbon
                    ? $this->statementPeriod['start']
                    : Carbon::parse($this->statementPeriod['start']);
                $query->where('time', '>=', $start);
            }
            if (isset($this->statementPeriod['end'])) {
                $end = $this->statementPeriod['end'] instanceof Carbon
                    ? $this->statementPeriod['end']
                    : Carbon::parse($this->statementPeriod['end']);
                $query->where('time', '<=', $end);
            }
        }

        $touchedInEvents = $query->get();

        $linkedCount = 0;

        foreach ($touchedInEvents as $touchedInEvent) {
            // Find corresponding touched_out event
            // It should be the next touched_out from the same actor (Oyster card) after this touched_in
            $touchedOutEvent = Event::where('integration_id', $this->integration->id)
                ->where('service', 'oyster')
                ->where('action', 'touched_out_at')
                ->where('actor_id', $touchedInEvent->actor_id)
                ->where('time', '>', $touchedInEvent->time)
                ->where('time', '<', $touchedInEvent->time->copy()->addHours(4)) // Max 4 hours for a journey
                ->orderBy('time', 'asc')
                ->first();

            if (! $touchedOutEvent) {
                // This might be a tram or bus journey (tap-on only)
                continue;
            }

            // Check if they have the same raw_action (same journey)
            $touchedInRaw = $touchedInEvent->event_metadata['raw_action'] ?? '';
            $touchedOutRaw = $touchedOutEvent->event_metadata['raw_action'] ?? '';

            // For journeys with both tap-in and tap-out, the raw_action should be the same
            // E.g., "Victoria to Oxford Circus" appears on both events
            if ($touchedInRaw !== $touchedOutRaw) {
                // Fallback: verify they share the same transport mode
                $touchedInMode = $touchedInEvent->event_metadata['transport_mode'] ?? null;
                $touchedOutMode = $touchedOutEvent->event_metadata['transport_mode'] ?? null;

                if ($touchedInMode !== $touchedOutMode) {
                    continue;
                }

                // Get the station objects to compare
                $touchedInTarget = $touchedInEvent->target;
                $touchedOutTarget = $touchedOutEvent->target;

                if (! $touchedInTarget || ! $touchedOutTarget) {
                    continue;
                }

                // Normalize station names for comparison
                $originName = strtolower(trim($touchedInTarget->title ?? ''));
                $destinationName = strtolower(trim($touchedOutTarget->title ?? ''));

                // The touched_in raw_action should mention the destination (format: "Origin to Destination")
                $touchedInRawLower = strtolower($touchedInRaw);
                $touchedOutRawLower = strtolower($touchedOutRaw);

                // Check if destination appears after "to" in the raw action
                $hasDestinationInTouchIn = preg_match('/\bto\b.*' . preg_quote($destinationName, '/') . '/i', $touchedInRawLower);
                $hasDestinationInTouchOut = str_contains($touchedOutRawLower, $destinationName);

                if (! $hasDestinationInTouchIn && ! $hasDestinationInTouchOut) {
                    continue;
                }
            }

            // Calculate journey duration
            $durationMinutes = $touchedInEvent->time->diffInMinutes($touchedOutEvent->time);

            // Create relationship linking the two events
            try {
                Relationship::findOrCreateRelationship(
                    [
                        'user_id' => $this->integration->user_id,
                        'from_type' => Event::class,
                        'from_id' => $touchedInEvent->id,
                        'to_type' => Event::class,
                        'to_id' => $touchedOutEvent->id,
                        'type' => 'caused_by',
                    ],
                    [
                        'metadata' => [
                            'journey_duration_minutes' => $durationMinutes,
                            'transport_mode' => $touchedInEvent->event_metadata['transport_mode'] ?? null,
                            'linked_at' => now()->toIso8601String(),
                        ],
                    ]
                );

                $linkedCount++;
            } catch (\Exception $e) {
                Log::warning('Oyster: Failed to create journey relationship', [
                    'touched_in_id' => $touchedInEvent->id,
                    'touched_out_id' => $touchedOutEvent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Oyster: Finished linking journey events', [
            'integration_id' => $this->integration->id,
            'total_touched_in' => $touchedInEvents->count(),
            'linked_count' => $linkedCount,
        ]);
    }

    public function uniqueId(): string
    {
        $periodHash = $this->statementPeriod
            ? md5(json_encode($this->statementPeriod))
            : 'all';

        return 'link_oyster_journeys_'.$this->integration->id.'_'.$periodHash;
    }
}
