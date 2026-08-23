<?php

namespace App\Integrations\HomeAssistant;

use App\Integrations\Base\WebhookPlugin;
use App\Integrations\Contracts\SupportsTaskPipeline;
use App\Jobs\OAuth\HomeAssistant\HomeAssistantMediaEnrichmentPull;
use App\Jobs\TaskPipeline\Tasks\ResolveHomeAssistantAttributionTask;
use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Services\HomeAssistant\HomeAssistantAttributionService;
use App\Services\TaskPipeline\TaskDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HomeAssistantPlugin extends WebhookPlugin implements SupportsTaskPipeline
{
    public static function getIdentifier(): string
    {
        return 'home_assistant';
    }

    public static function getDisplayName(): string
    {
        return 'Home Assistant';
    }

    public static function getDescription(): string
    {
        return 'Automatically track film and TV watching pushed from a Home Assistant media player automation.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'household_member_name' => [
                'type' => 'string',
                'label' => 'Other household member\'s name',
                'required' => false,
                'default' => 'Dan',
                'description' => 'Used when a watch on a shared media player is attributed to someone else in the household.',
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'media_watch' => [
                'label' => 'Media Watched',
                'schema' => self::getConfigurationSchema(),
                'description' => 'Watch events pushed from a Home Assistant media player automation.',
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.tv';
    }

    public static function getAccentColor(): string
    {
        return 'accent';
    }

    public static function getDomain(): string
    {
        return 'media';
    }

    public static function getActionTypes(): array
    {
        return [
            'watched' => [
                'icon' => 'fas.tv',
                'display_name' => 'Watched',
                'description' => 'Watched something on a Home Assistant media player',
                'display_with_object' => true,
                'value_unit' => 'minutes',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'media_details' => [
                'icon' => 'fas.tv',
                'display_name' => 'Media Details',
                'description' => 'Enriched title, overview, and rating from TMDB',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
            'flint_user_question' => [
                'icon' => 'fas.circle-question',
                'display_name' => 'Attribution Question',
                'description' => 'Flint-owned block type this plugin reacts to (not creates) to ask who was watching',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => true,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'home_assistant_user' => [
                'icon' => 'fas.user',
                'display_name' => 'User',
                'description' => 'The household member attributed with a watch',
                'hidden' => false,
            ],
            'tv_watch' => [
                'icon' => 'fas.tv',
                'display_name' => 'TV/Film Watch',
                'description' => 'Something watched on a Home Assistant media player, before enrichment resolves it',
                'hidden' => false,
            ],
            'movie' => [
                'icon' => 'fas.film',
                'display_name' => 'Movie',
                'description' => 'A watch resolved to a specific film via TMDB enrichment',
                'hidden' => false,
            ],
            'tv_episode' => [
                'icon' => 'fas.tv',
                'display_name' => 'TV Episode',
                'description' => 'A watch resolved to a specific TV show via TMDB enrichment',
                'hidden' => false,
            ],
        ];
    }

    /**
     * Reactive task definitions this plugin needs — auto-discovered by
     * TaskPipelineServiceProvider via the SupportsTaskPipeline interface.
     */
    public static function getTaskDefinitions(): array
    {
        return [
            new TaskDefinition(
                key: 'home_assistant_resolve_attribution',
                name: 'Resolve Home Assistant Watch Attribution',
                description: 'Reassigns or discards a Home Assistant "watched" event once the "who was watching" question has been answered.',
                jobClass: ResolveHomeAssistantAttributionTask::class,
                appliesTo: ['block'],
                conditions: ['block_type' => 'flint_user_question'],
                dependencies: [],
                queue: 'tasks',
                priority: 50,
                runOnCreate: false,
                runOnUpdate: true,
                shouldRun: function ($model) {
                    if (! $model instanceof Block) {
                        return false;
                    }

                    $metadata = $model->metadata ?? [];

                    return ($metadata['related_service'] ?? null) === self::getIdentifier()
                        && ! empty($metadata['answer'])
                        && empty($metadata['attribution_resolved_at']);
                },
            ),
        ];
    }

    /**
     * Handle the inbound webhook from Home Assistant's rest_command.
     *
     * Processed synchronously (no dedicated queued Hook/Data job pair) since
     * this integration has a single simple event shape and needs the created
     * Event back immediately to run attribution.
     */
    public function handleWebhook(Request $request, Integration $integration): void
    {
        if (! $this->verifyWebhookSignature($request, $integration)) {
            abort(401, 'Invalid webhook signature');
        }

        $payload = $request->all();
        $headers = $request->headers->all();
        $this->logWebhookPayload(static::getIdentifier(), $integration->id, $payload, $headers);

        $converted = $this->convertData($payload, $integration);

        foreach ($converted['events'] ?? [] as $eventData) {
            $this->createWatchedEvent($eventData, $integration);
        }
    }

    /**
     * Convert the flat JSON payload from the HA rest_command into the
     * standard actor/target/event shape.
     */
    public function convertData(array $externalData, Integration $integration): array
    {
        $title = trim((string) ($externalData['title'] ?? ''));

        if ($title === '') {
            return ['events' => []];
        }

        $minutesWatched = (int) ($externalData['minutes_watched'] ?? 15);
        $entityId = (string) ($externalData['entity_id'] ?? 'unknown');
        $appName = $externalData['app_name'] ?? null;
        $mediaContentType = $externalData['media_content_type'] ?? null;
        $time = now();

        return [
            'events' => [[
                'source_id' => 'home_assistant_watch_' . (string) Str::uuid(),
                'time' => $time,
                'actor' => [
                    'concept' => 'user',
                    'type' => 'home_assistant_user',
                    'title' => $integration->user->name ?? 'Household',
                    'metadata' => [],
                ],
                'target' => [
                    'concept' => 'media',
                    'type' => 'tv_watch',
                    'title' => $title,
                    'metadata' => [
                        'app_name' => $appName,
                        'media_content_type' => $mediaContentType,
                    ],
                ],
                'domain' => self::getDomain(),
                'action' => 'watched',
                'value' => $minutesWatched,
                'value_unit' => 'minutes',
                'event_metadata' => [
                    'entity_id' => $entityId,
                    'app_name' => $appName,
                    'media_content_type' => $mediaContentType,
                    'will_home' => $this->parseTriState($externalData['will_home'] ?? null),
                    'dan_home' => $this->parseTriState($externalData['dan_home'] ?? null),
                ],
            ]],
        ];
    }

    protected function createWatchedEvent(array $eventData, Integration $integration): Event
    {
        // Dedupe on entity + title within a short window rather than an
        // exact source_id match, so a rest_command retry or a repeated
        // automation trigger that lands in a different minute doesn't
        // produce a duplicate event (and a duplicate attribution question).
        $entityId = $eventData['event_metadata']['entity_id'] ?? null;
        $title = $eventData['target']['title'] ?? null;

        $existing = Event::where('integration_id', $integration->id)
            ->where('action', 'watched')
            ->where('event_metadata->entity_id', $entityId)
            ->where('time', '>=', $eventData['time']->copy()->subMinutes(30))
            ->whereHas('target', fn ($query) => $query->where('title', $title))
            ->first();

        if ($existing) {
            return $existing;
        }

        $actor = $this->createOrUpdateObject($eventData['actor'], $integration);
        $target = $this->createOrUpdateObject($eventData['target'], $integration);

        $event = Event::create([
            'source_id' => $eventData['source_id'],
            'time' => $eventData['time'],
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => $integration->service,
            'domain' => $eventData['domain'],
            'action' => $eventData['action'],
            'value' => $eventData['value'] ?? null,
            'value_multiplier' => 1,
            'value_unit' => $eventData['value_unit'] ?? null,
            'event_metadata' => $eventData['event_metadata'] ?? [],
            'target_id' => $target->id,
        ]);

        app(HomeAssistantAttributionService::class)->attribute($event, $integration);

        HomeAssistantMediaEnrichmentPull::dispatch($integration, $event->id, $target->title);

        return $event;
    }

    /**
     * Normalize a presence value coming from HA's Jinja templating
     * (which may render as a real bool, "True"/"False", "on"/"off", etc.)
     * into a tri-state bool: true, false, or null (unknown).
     */
    private function parseTriState(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'true', '1', 'on', 'home', 'yes' => true,
            'false', '0', 'off', 'not_home', 'no' => false,
            default => null,
        };
    }
}
