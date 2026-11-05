<?php

namespace App\Http\Resources\Compact;

use App\Integrations\PluginRegistry;
use App\Models\MetricStatistic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin MetricStatistic
 */
class CompactMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'identifier' => $this->mobileIdentifier(),
            'display_name' => $this->getDisplayName(),
            'service' => $this->service,
            'domain' => $this->domain(),
            'action' => $this->action,
            'unit' => $this->value_unit,
            'event_count' => $this->event_count,
            'mean' => $this->mean_value !== null ? (float) $this->mean_value : null,
            'last_event_at' => $this->last_event_at?->toIso8601String(),
        ];
    }

    private function mobileIdentifier(): string
    {
        return $this->service . '.' . Str::after($this->action, 'had_');
    }

    private function domain(): string
    {
        $action = Str::after($this->action, 'had_');

        if (in_array($action, [
            'steps',
            'step_count',
            'calories',
            'active_calories',
            'active_energy',
            'basal_energy_burned',
            'total_calories',
            'distance',
            'equivalent_walking_distance',
            'workout',
        ], true)) {
            return 'activity';
        }

        if (in_array($this->service, ['monzo', 'gocardless', 'financial', 'receipt'], true)) {
            return 'money';
        }

        if (in_array($this->service, ['spotify', 'screen_time', 'goodreads', 'untappd'], true)) {
            return 'media';
        }

        $pluginClass = PluginRegistry::getPlugin($this->service);

        return $pluginClass ? $pluginClass::getDomain() : 'health';
    }
}
