<?php

namespace App\Http\Resources\Compact;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Integration
 */
class CompactIntegrationResource extends JsonResource
{
    /**
     * `last_event_time` is read when the query selected it (see
     * IntegrationsController::index), so push sources don't cost a query each.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pluginClass = PluginRegistry::getPlugin($this->service);
        $lastEventTime = $this->last_event_time ?? null;

        return [
            'id' => $this->id,
            'service' => $this->service,
            'name' => $this->name,
            'instance_type' => $this->instance_type,
            'status' => $this->statusKey($lastEventTime ? Carbon::parse($lastEventTime) : null),
            'domain' => $pluginClass ? $pluginClass::getDomain() : null,
            'paused' => $this->isPaused(),
            'last_sync_at' => $this->last_successful_update_at?->toIso8601String(),
            'next_update_at' => $this->getNextUpdateTime()?->toIso8601String(),
            'schedule_summary' => $this->getScheduleSummary(),
        ];
    }
}
