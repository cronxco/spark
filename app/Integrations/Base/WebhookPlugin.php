<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class WebhookPlugin implements IntegrationPlugin
{
    public static function getServiceType(): string
    {
        return 'webhook';
    }

    public function initializeGroup(User $user): IntegrationGroup
    {
        $webhookSecret = Str::random(32);
        $webhookUrl = route('webhook.handle', [
            'service' => static::getIdentifier(),
            'secret' => $webhookSecret,
        ]);

        return IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => static::getIdentifier(),
            'account_id' => $webhookSecret,
            'access_token' => $webhookUrl,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
        ]);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = []): Integration
    {
        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => static::getDisplayName(),
            'instance_type' => $instanceType,
            'configuration' => $initialConfig,
            'account_id' => $group->account_id, // reuse secret if relevant
            'access_token' => $group->access_token, // webhook url for convenience
        ]);
    }

    public function handleWebhook(Request $request, Integration $integration): void
    {
        // Verify webhook signature if required
        if (! $this->verifyWebhookSignature($request, $integration)) {
            abort(401, 'Invalid webhook signature');
        }

        // Process the webhook payload
        $payload = $request->all();
        $convertedData = $this->convertData($payload, $integration);

        // Create events, objects, and blocks
        $this->createEventsFromWebhook($convertedData, $integration);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        // Webhook plugins don't handle OAuth callbacks
        throw new Exception('Webhook plugins do not handle OAuth callbacks');
    }

    public function fetchData(Integration $integration): void
    {
        // Webhook plugins don't fetch data
        throw new Exception('Webhook plugins do not fetch data');
    }

    protected function verifyWebhookSignature(Request $request, Integration $integration): bool
    {
        // Override in child classes if signature verification is needed
        return true;
    }

    protected function createEventsFromWebhook(array $convertedData, Integration $integration): void
    {
        foreach ($convertedData['events'] ?? [] as $eventData) {
            // Create actor object
            $actor = $this->createOrUpdateObject($eventData['actor'], $integration);

            // Create target object
            $target = $this->createOrUpdateObject($eventData['target'], $integration);

            // Create event
            $event = Event::create([
                'source_id' => $eventData['source_id'],
                'time' => $eventData['time'],
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'actor_metadata' => $eventData['actor_metadata'] ?? [],
                'service' => $integration->service,
                'domain' => $eventData['domain'],
                'action' => $eventData['action'],
                'value' => $eventData['value'] ?? null,
                'value_multiplier' => $eventData['value_multiplier'] ?? 1,
                'value_unit' => $eventData['value_unit'] ?? null,
                'event_metadata' => $eventData['event_metadata'] ?? [],
                'target_id' => $target->id,
                'target_metadata' => $eventData['target_metadata'] ?? [],
                'embeddings' => $eventData['embeddings'] ?? null,
            ]);

            // Create blocks if any
            foreach ($eventData['blocks'] ?? [] as $blockData) {
                $event->blocks()->create([
                    'time' => $blockData['time'] ?? now(),
                    'title' => $blockData['title'],
                    'content' => $blockData['content'],
                    'url' => $blockData['url'] ?? null,
                    'media_url' => $blockData['media_url'] ?? null,
                    'value' => $blockData['value'] ?? null,
                    'value_multiplier' => $blockData['value_multiplier'] ?? 1,
                    'value_unit' => $blockData['value_unit'] ?? null,
                    'embeddings' => $blockData['embeddings'] ?? null,
                ]);
            }
        }
    }

    protected function createOrUpdateObject(array $objectData, Integration $integration): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => $objectData['concept'],
                'type' => $objectData['type'],
                'title' => $objectData['title'],
            ],
            [
                'time' => $objectData['time'] ?? now(),
                'content' => $objectData['content'] ?? null,
                'metadata' => $objectData['metadata'] ?? [],
                'url' => $objectData['url'] ?? null,
                'image_url' => $objectData['image_url'] ?? null,
                'embeddings' => $objectData['embeddings'] ?? null,
            ]
        );
    }
}
