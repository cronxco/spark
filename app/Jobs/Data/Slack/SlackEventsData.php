<?php

namespace App\Jobs\Data\Slack;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Exception;
use Illuminate\Support\Facades\Log;

class SlackEventsData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'slack';
    }

    protected function getJobType(): string
    {
        return 'events';
    }

    protected function process(): void
    {
        $convertedData = $this->rawData;

        if (empty($convertedData['events'])) {
            Log::info('Slack Events Data: No events to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        foreach ($convertedData['events'] as $eventData) {
            try {
                $this->processSlackEvent($eventData);
            } catch (Exception $e) {
                Log::error('Slack Events Data: Failed to process event', [
                    'integration_id' => $this->integration->id,
                    'source_id' => $eventData['source_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processSlackEvent(array $eventData): void
    {
        $sourceId = $eventData['source_id'];

        // Check if this event already exists
        $existingEvent = Event::where('integration_id', $this->integration->id)
            ->where('source_id', $sourceId)
            ->first();

        if ($existingEvent) {
            Log::debug('Slack: Event already exists, skipping', [
                'integration_id' => $this->integration->id,
                'source_id' => $sourceId,
            ]);

            return;
        }

        Log::info('Slack: Processing event', [
            'integration_id' => $this->integration->id,
            'source_id' => $sourceId,
            'action' => $eventData['action'],
        ]);

        // Create or update actor object
        $actor = $this->createOrUpdateObject($eventData['actor']);

        // Create or update target object
        $target = $this->createOrUpdateObject($eventData['target']);

        // Create the event safely with race condition protection
        $event = Event::updateOrCreate(
            [
                'integration_id' => $this->integration->id,
                'source_id' => $sourceId,
            ],
            [
                'time' => $eventData['time'],
                'actor_id' => $actor->id,
                'service' => $eventData['service'],
                'domain' => $eventData['domain'],
                'action' => $eventData['action'],
                'value' => $eventData['value'] ?? null,
                'value_multiplier' => $eventData['value_multiplier'] ?? 1,
                'value_unit' => $eventData['value_unit'] ?? null,
                'event_metadata' => $eventData['event_metadata'] ?? [],
                'target_id' => $target->id,
            ]
        );

        // Add blocks if any
        if (! empty($eventData['blocks'])) {
            foreach ($eventData['blocks'] as $blockData) {
                $event->createBlock([
                    'time' => $blockData['time'] ?? $event->time,
                    'block_type' => $blockData['block_type'] ?? 'generic',
                    'title' => $blockData['title'],
                    'metadata' => $blockData['metadata'] ?? [],
                    'url' => $blockData['url'] ?? null,
                    'value' => $blockData['value'] ?? null,
                    'value_multiplier' => $blockData['value_multiplier'] ?? 1,
                    'value_unit' => $blockData['value_unit'] ?? null,
                ]);
            }
        }

        // Add tags
        $this->addSlackTags($event, $eventData);

        // Add channel-specific tags
        if (! empty($eventData['event_metadata']['channel'])) {
            $event->attachTag('channel_'.$eventData['event_metadata']['channel'], 'slack_channel');
            $event->attachTag($eventData['action'], 'slack_action');
        } else {
            $event->attachTag($eventData['action'], 'slack_action');
        }
    }

    private function addSlackTags(Event $event, array $eventData): void
    {
        $event->attachTag($eventData['action'], 'slack_action');

        // Add team tag if available
        if (! empty($eventData['event_metadata']['team'])) {
            $event->attachTag('team_'.$eventData['event_metadata']['team'], 'slack_team');
        }

        // Add channel tag if available
        if (! empty($eventData['event_metadata']['channel'])) {
            $event->attachTag('channel_'.$eventData['event_metadata']['channel'], 'slack_channel');
        }

        // Add subtype tag for messages
        if (! empty($eventData['event_metadata']['subtype'])) {
            $event->attachTag('subtype_'.$eventData['event_metadata']['subtype'], 'slack_subtype');
        }

        // Add reaction tag if available
        if (! empty($eventData['event_metadata']['reaction'])) {
            $event->attachTag('reaction_'.str_replace([':', '-'], '_', $eventData['event_metadata']['reaction']), 'slack_reaction');
        }

        // Add file type tag if available
        if (! empty($eventData['event_metadata']['file_type'])) {
            $event->attachTag('file_'.$eventData['event_metadata']['file_type'], 'slack_file_type');
        }
    }
}
