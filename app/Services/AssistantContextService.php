<?php

namespace App\Services;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssistantContextService
{
    /**
     * Generate JSON context for assistant (all timeframes)
     *
     * @param  Carbon|null  $baseDate  - "Today" reference (defaults to now)
     * @param  Integration  $assistantIntegration  - The Flint integration instance
     * @param  array|null  $domains  - Optional array of domains to filter by (e.g., ['health', 'money'])
     * @return array - Structured JSON array
     */
    public function generateContext(User $user, ?Carbon $baseDate, Integration $assistantIntegration, ?array $domains = null): array
    {
        $baseDate = $baseDate ?? now();

        return [
            'yesterday' => $this->generateTimeframeContext($user, 'yesterday', $baseDate, $assistantIntegration, $domains),
            'today' => $this->generateTimeframeContext($user, 'today', $baseDate, $assistantIntegration, $domains),
            'tomorrow' => $this->generateTimeframeContext($user, 'tomorrow', $baseDate, $assistantIntegration, $domains),
            'day_2' => $this->generateTimeframeContext($user, 'day_2', $baseDate, $assistantIntegration, $domains),
            'day_3' => $this->generateTimeframeContext($user, 'day_3', $baseDate, $assistantIntegration, $domains),
            'day_4' => $this->generateTimeframeContext($user, 'day_4', $baseDate, $assistantIntegration, $domains),
            'day_5' => $this->generateTimeframeContext($user, 'day_5', $baseDate, $assistantIntegration, $domains),
            'day_6' => $this->generateTimeframeContext($user, 'day_6', $baseDate, $assistantIntegration, $domains),
            'day_7' => $this->generateTimeframeContext($user, 'day_7', $baseDate, $assistantIntegration, $domains),
        ];
    }

    /**
     * Generate context for a specific timeframe
     *
     * @param  string  $timeframe  - 'yesterday', 'today', 'tomorrow', 'day_2'...'day_7'
     * @param  Integration  $assistantIntegration  - The Flint integration instance
     * @param  array|null  $domains  - Optional array of domains to filter by
     */
    public function generateTimeframeContext(
        User $user,
        string $timeframe,
        Carbon $baseDate,
        Integration $assistantIntegration,
        ?array $domains = null
    ): array {
        $config = $this->getTimeframeConfig($assistantIntegration, $timeframe);

        // Check if timeframe is enabled
        if (! ($config['enabled'] ?? true)) {
            return [
                'date' => $this->getDateForTimeframe($timeframe, $baseDate)->toDateString(),
                'timezone' => $user->timezone ?? 'UTC',
                'event_count' => 0,
                'group_count' => 0,
                'service_breakdown' => [],
                'groups' => [],
                'relationships' => [],
            ];
        }

        // Calculate date range
        [$startDate, $endDate] = $this->getDateRangeForTimeframe($timeframe, $baseDate);

        // Query events
        $events = $this->queryEvents($user, $startDate, $endDate, $config, $domains);

        // Group events like day view
        $groups = $this->groupEvents($events, $user, $config);

        // Service breakdown
        $serviceBreakdown = $events->groupBy('service')
            ->map(fn ($serviceEvents) => $serviceEvents->count())
            ->all();

        // Query relationships if enabled
        $relationships = [];
        if ($config['include_relationships'] ?? true) {
            $relationshipModels = $this->queryRelationships($events);
            $relationships = $relationshipModels->map(fn ($rel) => $this->transformRelationship($rel))->all();
        }

        return [
            'date' => $startDate->toDateString(),
            'timezone' => $user->timezone ?? 'UTC',
            'event_count' => $events->count(),
            'group_count' => count($groups),
            'service_breakdown' => $serviceBreakdown,
            'groups' => $groups,
            'relationships' => $relationships,
        ];
    }

    /**
     * Get configuration for timeframe from integration
     */
    protected function getTimeframeConfig(Integration $integration, string $timeframe): array
    {
        $configuration = $integration->configuration ?? [];
        $enabledKey = "{$timeframe}_enabled";
        $servicesKey = "{$timeframe}_services";

        return [
            'enabled' => $configuration[$enabledKey] ?? true,
            'services' => $configuration[$servicesKey] ?? [],
            'integrations' => $configuration["{$timeframe}_integrations"] ?? [],
            'excluded_block_types' => $configuration['excluded_block_types'] ?? [],
            'include_relationships' => $configuration['include_relationships'] ?? true,
            'max_events' => $configuration['max_events_per_timeframe'] ?? config('spark.assistant.max_events', 200),
        ];
    }

    /**
     * Get date for timeframe
     */
    protected function getDateForTimeframe(string $timeframe, Carbon $baseDate): Carbon
    {
        return match ($timeframe) {
            'yesterday' => $baseDate->copy()->subDay(),
            'today' => $baseDate->copy(),
            'tomorrow', 'day_2' => $baseDate->copy()->addDay(),
            'day_3' => $baseDate->copy()->addDays(2),
            'day_4' => $baseDate->copy()->addDays(3),
            'day_5' => $baseDate->copy()->addDays(4),
            'day_6' => $baseDate->copy()->addDays(5),
            'day_7' => $baseDate->copy()->addDays(6),
            default => $baseDate->copy(),
        };
    }

    /**
     * Get date range for timeframe
     */
    protected function getDateRangeForTimeframe(string $timeframe, Carbon $baseDate): array
    {
        $date = $this->getDateForTimeframe($timeframe, $baseDate);

        return [
            $date->copy()->startOfDay(),
            $date->copy()->endOfDay(),
        ];
    }

    /**
     * Query events for date range with filters
     */
    protected function queryEvents(
        User $user,
        Carbon $startDate,
        Carbon $endDate,
        array $config,
        ?array $domains = null
    ): Collection {
        $query = Event::query()
            ->whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('time', [$startDate, $endDate])
            ->with(['actor', 'target', 'blocks', 'tags']);

        // Apply domain filter (if specified)
        if (! empty($domains) && is_array($domains)) {
            $query->whereIn('domain', $domains);
        }

        // Apply service filters (if specified)
        $enabledServices = $config['services'];
        if (! empty($enabledServices) && is_array($enabledServices)) {
            $query->whereIn('service', $enabledServices);
        }

        // Apply integration instance filters (if specified)
        $enabledIntegrationIds = $config['integrations'];
        if (! empty($enabledIntegrationIds) && is_array($enabledIntegrationIds)) {
            $query->whereIn('integration_id', $enabledIntegrationIds);
        }

        // Apply max events limit
        $maxEvents = $config['max_events'] ?? 200;

        $events = $query->orderBy('time', 'desc')
            ->limit($maxEvents)
            ->get();

        // Filter out excluded action types (e.g., had_balance with exclude_from_flint flag)
        return $events->reject(function ($event) {
            return $this->shouldExcludeAction($event->service, $event->action);
        });
    }

    /**
     * Group events like day view (service::action::hour)
     */
    protected function groupEvents(Collection $events, User $user, array $config): array
    {
        $groups = [];
        $currentKey = null;
        $currentHour = null;
        $currentGroup = null;

        foreach ($events as $event) {
            $key = $event->service . '::' . $event->action;
            $hour = to_user_timezone($event->time, $user)->format('H');

            if ($currentKey !== $key || $currentHour !== $hour) {
                // Save previous group
                if ($currentGroup) {
                    $groups[] = $this->finalizeGroup($currentGroup, $user, $config);
                }

                // Start new group
                $currentKey = $key;
                $currentHour = $hour;
                $currentGroup = [
                    'service' => $event->service,
                    'action' => $event->action,
                    'hour' => $hour,
                    'events' => [],
                ];
            }

            $currentGroup['events'][] = $event;
        }

        if ($currentGroup) {
            $groups[] = $this->finalizeGroup($currentGroup, $user, $config);
        }

        return $groups;
    }

    /**
     * Finalize a group with metadata and transformed events
     */
    protected function finalizeGroup(array $group, User $user, array $config): array
    {
        $count = count($group['events']);
        $sample = $group['events'][0] ?? null;
        $objectTypePlural = 'items';

        if ($sample && $sample->target && $sample->target->type) {
            $type = $sample->target->type;
            $objectTypePlural = Str::plural(Str::headline($type));
        }

        $formattedAction = format_action_title($group['action']);

        // Get timezone hour range
        $sampleTime = $sample ? to_user_timezone($sample->time, $user) : null;
        $timezoneHour = $sampleTime ? $sampleTime->format('H:00') . ' - ' . $sampleTime->copy()->addHour()->format('H:00') . ' ' . $sampleTime->format('T') : '';

        return [
            'service' => $group['service'],
            'action' => $group['action'],
            'hour' => $group['hour'],
            'timezone_hour' => $timezoneHour,
            'count' => $count,
            'object_type_plural' => $objectTypePlural,
            'summary' => $formattedAction . ' ' . $count . ' ' . $objectTypePlural,
            'is_condensed' => $count >= 4,
            'formatted_action' => $formattedAction,
            'first_event' => $this->transformEvent($group['events'][0], $config),
            'all_events' => array_map(
                fn ($e) => $this->transformEvent($e, $config),
                $group['events']
            ),
        ];
    }

    /**
     * Transform Event model to clean JSON
     */
    protected function transformEvent(Event $event, array $config): array
    {
        $data = [
            'time' => $event->time->toISOString(),
            'updated_at' => $event->updated_at->toISOString(),
            'service' => $event->service,
            'domain' => $event->domain,
            'action' => $event->action,
        ];

        // Actor/Target denormalization
        if ($event->actor) {
            $data['actor'] = $this->transformEventObject($event->actor);
        }
        if ($event->target) {
            $data['target'] = $this->transformEventObject($event->target);
        }

        // Value formatting (apply multiplier)
        if ($event->value !== null) {
            $data['value'] = $event->formatted_value;
            $data['unit'] = $event->value_unit;
        }

        // Tags
        if ($event->tags->isNotEmpty()) {
            $data['tags'] = $event->tags->pluck('name')->all();
        }

        // URL if present
        if ($event->url) {
            $data['url'] = $event->url;
        }

        // Blocks (filtered by exclusion list)
        $excludedTypes = $config['excluded_block_types'] ?? [];
        $filteredBlocks = $event->blocks->filter(fn ($block) => ! $this->shouldExcludeBlock($block, $excludedTypes));

        if ($filteredBlocks->isNotEmpty()) {
            $data['blocks'] = $filteredBlocks->map(fn ($b) => $this->transformBlock($b))->values()->all();
        }

        return $data;
    }

    /**
     * Transform EventObject to clean JSON
     */
    protected function transformEventObject(?EventObject $object): ?array
    {
        if (! $object) {
            return null;
        }

        $data = [
            'title' => $object->title,
            'concept' => $object->concept,
            'type' => $object->type,
        ];

        if ($object->content) {
            $data['content'] = $object->content;
        }

        if ($object->url) {
            $data['url'] = $object->url;
        }

        return $data;
    }

    /**
     * Transform Block to clean JSON
     */
    protected function transformBlock(Block $block): array
    {
        $content = $block->getContent();

        // Limit content to 500 chars to save tokens
        if (mb_strlen($content, 'UTF-8') > 500) {
            $content = mb_substr($content, 0, 500, 'UTF-8') . '...';
        }

        $data = [
            'type' => $block->block_type,
            'title' => $block->title,
            'updated_at' => $block->updated_at->toISOString(),
        ];

        if ($content) {
            $data['content'] = $content;
        }

        if ($block->value !== null) {
            $data['value'] = $block->formatted_value;
            $data['unit'] = $block->value_unit;
        }

        return $data;
    }

    /**
     * Check if block type should be excluded
     */
    protected function shouldExcludeBlock(Block $block, array $excludedTypes): bool
    {
        // Explicitly excluded types
        if (in_array($block->block_type, $excludedTypes)) {
            return true;
        }

        // Default: exclude raw blocks if no explicit exclusions
        if (empty($excludedTypes) && str_ends_with($block->block_type, '_raw')) {
            return true;
        }

        return false;
    }

    /**
     * Check if action type should be excluded from Flint context
     */
    protected function shouldExcludeAction(string $service, string $action): bool
    {
        $plugin = PluginRegistry::getPlugin($service);
        if (! $plugin) {
            return false;
        }

        $actionTypes = $plugin::getActionTypes();
        if (! isset($actionTypes[$action])) {
            return false;
        }

        return $actionTypes[$action]['exclude_from_flint'] ?? false;
    }

    /**
     * Query relationships for events
     */
    protected function queryRelationships(Collection $events): Collection
    {
        if ($events->isEmpty()) {
            return collect();
        }

        $eventIds = $events->pluck('id')->all();

        return Relationship::where(function ($query) use ($eventIds) {
            $query->where('from_type', Event::class)
                ->whereIn('from_id', $eventIds);
        })->orWhere(function ($query) use ($eventIds) {
            $query->where('to_type', Event::class)
                ->whereIn('to_id', $eventIds);
        })
            ->with(['from', 'to'])
            ->get();
    }

    /**
     * Transform Relationship to clean JSON
     */
    protected function transformRelationship(Relationship $relationship): array
    {
        $data = [
            'type' => $relationship->type,
        ];

        // Include basic event info for from/to
        if ($relationship->from instanceof Event) {
            $data['from_event'] = [
                'time' => $relationship->from->time->toISOString(),
                'service' => $relationship->from->service,
                'action' => $relationship->from->action,
            ];
        }

        if ($relationship->to instanceof Event) {
            $data['to_event'] = [
                'time' => $relationship->to->time->toISOString(),
                'service' => $relationship->to->service,
                'action' => $relationship->to->action,
            ];
        }

        // Include metadata if present
        if ($relationship->metadata && ! empty($relationship->metadata)) {
            $data['metadata'] = $relationship->metadata;
        }

        // Include value if monetary relationship
        if ($relationship->value !== null) {
            $data['value'] = $relationship->value / ($relationship->value_multiplier ?? 1);
            $data['unit'] = $relationship->value_unit;
        }

        return $data;
    }
}
